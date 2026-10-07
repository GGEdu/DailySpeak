# DailySpeak
DailySpeak es una aplicación de aprendizaje de idiomas mediante IA diseñada para usuarios con nivel B2/C1. Resuelve el problema de la "latencia cognitiva" y el "síndrome de la página en blanco" utilizando noticias diarias reales (vía RSS) como ancla para debates de voz en tiempo real con un tutor de IA estricto.

La especificación técnica completa está en [`Architecture.md`](Architecture.md).

## Stack

- Laravel 13 (PHP 8.3+) y Laravel AI SDK (`laravel/ai`)
- Laravel Reverb (WebSockets), Horizon (colas en Redis) y Sanctum (API)
- Frontend: Inertia.js v3 + Vue 3, Tailwind CSS v4 y Laravel Echo (`@laravel/echo-vue`)
- PostgreSQL (columnas `jsonb`), Redis, Meilisearch y Mailpit vía Laravel Sail

## Puesta en marcha (Laravel Sail)

Requisitos: Docker y PHP 8.3+ con Composer en el host (solo para el primer `composer install`).

```bash
cp .env.example .env
composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
./vendor/bin/sail npm install
./vendor/bin/sail npm run build     # o `sail npm run dev` para recarga en caliente
```

Abre http://localhost y entra con el usuario de prueba, o crea una cuenta.

El seeder crea el usuario `test@example.com` (contraseña `password`, nivel C1), cinco noticias, un debate completado con sus mensajes y vocabulario de ejemplo.

Servicios expuestos por defecto:

| Servicio    | URL / puerto            |
|-------------|-------------------------|
| App         | http://localhost        |
| Horizon     | http://localhost/horizon |
| Reverb (WS) | `ws://localhost:8080`   |
| PostgreSQL  | `localhost:5432`        |
| Redis       | `localhost:6379`        |
| Meilisearch | http://localhost:7700   |
| Mailpit     | http://localhost:8025   |

## Ingesta de noticias (News Harvester)

El comando `news:fetch` lee los feeds RSS configurados, descarga el texto de cada artículo nuevo, lo resume con el agente `NewsSummarizer` (Laravel AI SDK) y lo guarda en `news_articles`. Está programado a diario a las 03:00 hora de Madrid (`NEWS_FETCH_TIME` / `NEWS_FETCH_TIMEZONE`); las fechas se siguen guardando en UTC.

En Sail, el servicio `scheduler` ejecuta `php artisan schedule:work` de forma continua. En producción basta una entrada de cron que lance el scheduler cada minuto:

```cron
* * * * * cd /ruta/a/dailyspeak && php artisan schedule:run >> /dev/null 2>&1
```

```bash
./vendor/bin/sail artisan news:fetch                 # todas las fuentes activas
./vendor/bin/sail artisan news:fetch --source=2      # solo una fuente (aunque esté pausada)
./vendor/bin/sail artisan news:fetch --limit=2       # máx. artículos nuevos por feed
./vendor/bin/sail artisan news:fetch --feed=https://feeds.bbci.co.uk/news/technology/rss.xml
./vendor/bin/sail artisan schedule:list              # próximas ejecuciones (en UTC)
```

Configuración (`.env`):

| Variable                      | Por defecto                                   | Descripción |
|-------------------------------|-----------------------------------------------|-------------|
| `GEMINI_API_KEY` / `OPENAI_API_KEY` | —                                       | Clave del proveedor elegido |
| `NEWS_AI_PROVIDER`            | `gemini`                                      | Proveedor del Laravel AI SDK (`gemini`, `openai`, …) |
| `NEWS_AI_MODEL`               | modelo por defecto del proveedor              | p. ej. `gemini-2.5-flash` o `gpt-4o-mini` |
| `NEWS_MAX_ARTICLES_PER_FEED`  | `5`                                           | Cada artículo nuevo es una llamada al LLM |

Las fuentes RSS se guardan en la tabla `news_sources` y se gestionan desde **/admin/sources** (solo administradores): alta con validación (el feed se descarga y se comprueba antes de guardarlo), pausar/activar, eliminar y «Fetch now» para leer una fuente al momento por la cola. La migración crea BBC News – World como fuente inicial; úsala solo en desarrollo, ya que sus condiciones exigen licencia para uso comercial.

El prompt de resumen está en `config/prompts.php`. Los artículos ya guardados no se vuelven a resumir, y los que no tienen texto suficiente en su página (vídeos, directos) se omiten en lugar de resumirse a partir de la entradilla del RSS.

## Debate por voz (API + WebSockets)

Sail levanta tres servicios extra con la misma imagen de la app: `reverb` (servidor WebSocket), `horizon` (workers de la cola `debates`) y `scheduler` (tareas programadas). Tras cambiar código PHP, reinicia los workers con `./vendor/bin/sail restart horizon`.

Flujo de un turno:

1. `POST /api/news-articles/{id}/debates` inicia un debate (o reanuda el activo) y devuelve el canal privado (`debates.{id}`).
2. El cliente se suscribe con Laravel Echo a ese canal (autorización en `/broadcasting/auth`, solo el dueño del debate).
3. `POST /api/debates/{id}/audio` (multipart, campo `audio`: webm, ogg, m4a, mp4, mp3, wav o flac; máx. 10 MB) guarda la grabación, encola `ProcessVoiceDebate` y responde `202`.
4. El job transcribe el audio (STT), pide la réplica al agente `DebateTutor` con el historial del debate (LLM), la sintetiza a MP3 (TTS) y guarda ambos mensajes en `debate_messages`.
5. Se emite `AIResponseGenerated` (`ShouldBroadcastNow`) con `transcript`, `audio_url` (URL firmada válida 60 min) y `user_message`. Si no se oye nada o el turno falla tras reintentar, se emite `DebateTurnFailed` con `reason` = `no_speech` | `processing_failed`.

Las rutas de la API usan Sanctum (`auth:sanctum`): cookie de sesión para la web o token Bearer para otros clientes. Los turnos se procesan de uno en uno por debate y la subida está limitada a 20 por minuto.

| Variable                | Por defecto  | Descripción |
|-------------------------|--------------|-------------|
| `DEBATE_STT_PROVIDER` / `DEBATE_STT_MODEL` | `openai` / `whisper-1` | Transcripción (p. ej. `groq` / `whisper-large-v3-turbo`) |
| `DEBATE_LLM_PROVIDER` / `DEBATE_LLM_MODEL` | `gemini` / por defecto del proveedor | Tutor de debate |
| `DEBATE_TTS_PROVIDER` / `DEBATE_TTS_MODEL` | `openai` / por defecto del proveedor | Síntesis de voz (p. ej. `eleven`) |
| `DEBATE_TTS_VOICE`      | `alloy`      | Voz de OpenAI o id de voz de ElevenLabs |
| `ELEVENLABS_API_KEY` / `GROQ_API_KEY` | — | Solo si se usan esos proveedores |

El system prompt del tutor está en `config/prompts.php` (`debate_tutor`).

## Frontend (web MVP)

Inertia + Vue 3 con un diseño oscuro (`resources/js`):

| Ruta              | Página                | Qué hace |
|-------------------|-----------------------|----------|
| `/`               | `pages/Welcome.vue`   | Landing para invitados (los usuarios autenticados van al feed) |
| `/login`, `/register` | `pages/auth/*`    | Acceso y registro (con el nivel B1/B2/C1) |
| `/feed`           | `pages/Feed.vue`      | Noticias del día agrupadas por fecha: título, resumen, vocabulario clave y «Debate this» / «Continue debate» |
| `/debates/{id}`   | `pages/Debate.vue`    | Chat de voz con el tutor |

La vista de debate graba con `MediaRecorder` y usa la Web Audio API (`AnalyserNode`) para el visualizador del micrófono y de la respuesta. Muestra el estado **Listening → Thinking → Speaking**, sube cada turno a la API con la sesión del navegador y escucha `AIResponseGenerated` / `DebateTurnFailed` en el canal privado con Echo para reproducir la respuesta automáticamente. Se puede interrumpir al tutor pulsando el micro y usar la barra espaciadora como atajo.

## Privacidad (RGPD)

Aplicado:

- Las grabaciones de voz de los usuarios se borran a los **7 días** (`DEBATE_AUDIO_RETENTION_DAYS`) con `debates:prune-recordings`, programado a las 04:00 (hora de Madrid). Se conservan las transcripciones y las respuestas sintetizadas del tutor. También se borran las grabaciones huérfanas (turnos sin voz o fallidos).
- Las grabaciones se guardan en disco privado y solo se sirven con URLs firmadas que caducan.

Pendiente de ampliar:

- [ ] Consentimiento explícito al registrarse (tratamiento de voz y envío a proveedores de IA).
- [ ] Transferencias internacionales: el audio y las transcripciones se envían a OpenAI/Google/NVIDIA; revisar contratos (DPA) y la base legal.
- [ ] Borrado de cuenta por el usuario (la base de datos ya borra en cascada debates, mensajes y vocabulario) y exportación de sus datos.
- [ ] Política de retención de transcripciones, informes de fluidez y audios del tutor.
- [ ] Registro de actividades de tratamiento y política de privacidad pública.

## Administradores

```bash
./vendor/bin/sail artisan user:admin tu@email.com            # conceder
./vendor/bin/sail artisan user:admin tu@email.com --revoke   # retirar
```

Los administradores gestionan las fuentes de noticias, no tienen límite de turnos de voz (el resto: 20 por minuto) y pueden abrir Horizon fuera de local. El usuario de prueba del seeder es administrador.

## Tests

Los tests usan la base de datos `testing` del contenedor de PostgreSQL (Sail la crea automáticamente):

```bash
./vendor/bin/sail artisan test
```

## Modelo de datos

| Tabla               | Modelo           | Notas |
|---------------------|------------------|-------|
| `users`             | `User`           | `current_level` → enum `EnglishLevel` (B1, B2, C1) |
| `news_articles`     | `NewsArticle`    | `key_vocabulary` (`jsonb`) → `array`; `source_url` único |
| `debates`           | `Debate`         | `status` → enum `DebateStatus`; `ai_feedback` (`jsonb`) → `array`; `started_at` / `ended_at` |
| `debate_messages`   | `DebateMessage`  | `role` → enum `MessageRole`; solo `created_at` (mensajes inmutables) |
| `user_vocabularies` | `UserVocabulary` | `mastery_level` 1–5 (CHECK en Postgres); `word` único por usuario |

## Roadmap

- [x] Fase 1: Setup (Sail), migraciones y modelos
- [x] Fase 2: Ingesta de noticias (News Harvester)
- [x] Fase 3: Motor de debate por voz y WebSockets (Reverb + Horizon)
- [x] Fase 4: Frontend web MVP
- [ ] Análisis de fluidez post-sesión (`ai_feedback`, Flujo C de `Architecture.md`)
