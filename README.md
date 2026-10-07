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
./vendor/bin/sail artisan scout:sync-index-settings
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

El comando `news:fetch` lee los feeds RSS configurados, descarga el texto de cada artículo nuevo, lo resume con el agente `NewsSummarizer` (Laravel AI SDK) y lo guarda en `news_articles`. Está programado a diario a las 03:00 hora de Madrid (`NEWS_FETCH_TIME` / `SCHEDULE_TIMEZONE`); las fechas se siguen guardando en UTC.

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

## Búsqueda de noticias (Meilisearch)

El buscador del feed (`/feed?q=…`) consulta todas las noticias, no solo las 20 últimas, con Laravel Scout y Meilisearch: tolera erratas («productivty» encuentra «productivity»), exige que aparezcan todas las palabras y ordena los resultados de más reciente a más antiguo. Busca en el título, el vocabulario clave y el resumen, por ese orden de importancia (`config/scout.php`).

- Los artículos se indexan en segundo plano (cola `default`) al crearse, modificarse o borrarse, así que el recolector nunca espera al buscador.
- Si Meilisearch no responde, el feed sigue funcionando con una búsqueda simple en PostgreSQL (`ILIKE` sobre título y resumen) y el error queda en el log.
- Tras cambiar los ajustes del índice: `sail artisan scout:sync-index-settings`. Para reindexar todo: `sail artisan scout:import "App\Models\NewsArticle"`.
- En producción, define `MEILISEARCH_KEY` (la *master key* o una clave con permisos de búsqueda e indexado).
- Limitación: Meilisearch no reduce las palabras a su raíz, así que «geoengineering» no encuentra «geoengineer» (sí lo hace «geoengineer» o el prefijo «geoengin»).

## Debate por voz (API + WebSockets)

Sail levanta tres servicios extra con la misma imagen de la app: `reverb` (servidor WebSocket), `horizon` (workers de la cola `debates`) y `scheduler` (tareas programadas). Tras cambiar código PHP, reinicia los workers con `./vendor/bin/sail restart horizon`.

Flujo de un turno:

1. `POST /api/news-articles/{id}/debates` inicia un debate (o reanuda el activo) y devuelve el canal privado (`debates.{id}`).
2. El cliente se suscribe con Laravel Echo a ese canal (autorización en `/broadcasting/auth`, solo el dueño del debate).
3. `POST /api/debates/{id}/audio` (multipart, campo `audio`: webm, ogg, m4a, mp4, mp3, wav o flac; máx. 10 MB) guarda la grabación, encola `ProcessVoiceDebate` y responde `202`.
4. El job transcribe el audio (STT) y emite `UserTurnTranscribed` con la transcripción, para que el usuario la vea mientras el tutor piensa. Después pide la réplica al agente `DebateTutor` con los últimos 30 mensajes del debate (LLM), la sintetiza a MP3 (TTS) y guarda ambos mensajes en `debate_messages`.
5. Se emite `AIResponseGenerated` (`ShouldBroadcastNow`) con `transcript`, `audio_url` (URL firmada válida 60 min) y `user_message`. Si no se oye nada o el turno falla tras reintentar, se emite `DebateTurnFailed` con `reason` = `no_speech` | `processing_failed`.

Las rutas de la API usan Sanctum (`auth:sanctum`): cookie de sesión para la web o token Bearer para otros clientes. Los turnos se procesan de uno en uno por debate y la subida está limitada a 20 por minuto.

| Variable                | Por defecto  | Descripción |
|-------------------------|--------------|-------------|
| `DEBATE_STT_PROVIDER` / `DEBATE_STT_MODEL` | `openai` / `whisper-1` | Transcripción (p. ej. `groq` / `whisper-large-v3-turbo`) |
| `DEBATE_LLM_PROVIDER` / `DEBATE_LLM_MODEL` | `gemini` / por defecto del proveedor | Tutor de debate |
| `DEBATE_LLM_OPTIONS`    | —            | JSON que se añade a cada petición del tutor, p. ej. `'{"reasoning_effort":"low"}'` (ver Latencia) |
| `DEBATE_TTS_PROVIDER` / `DEBATE_TTS_MODEL` | `openai` / por defecto del proveedor | Síntesis de voz (p. ej. `eleven`) |
| `DEBATE_TTS_VOICE`      | `alloy`      | Voz de OpenAI o id de voz de ElevenLabs |
| `ELEVENLABS_API_KEY` / `GROQ_API_KEY` | — | Solo si se usan esos proveedores |

El system prompt del tutor está en `config/prompts.php` (`debate_tutor`).

### Latencia

Cada turno respondido deja en el log una línea `Voice turn answered.` con el desglose en milisegundos: `queue_ms` (espera en la cola), `stt_ms`, `llm_ms`, `tts_ms` y `total_ms` (desde que se subió el audio hasta que la réplica está lista):

```bash
./vendor/bin/sail exec laravel.test grep "Voice turn answered" storage/logs/laravel.log | tail
```

Medido en desarrollo con NVIDIA `openai/gpt-oss-20b` (STT/TTS simulados), 3 turnos por configuración:

| Configuración | Cola | LLM | Total |
|---|---|---|---|
| Antes: workers que sondean Redis cada 3 s, `reasoning_effort` bajo | 0,9–2 s | 1,6–2,1 s | 2,8–3,7 s |
| Workers que esperan en Redis (`block_for`), razonamiento por defecto | 8–62 ms | 2,8–7,2 s | 2,8–7,3 s |
| Workers que esperan en Redis + `reasoning_effort` bajo (`.env` de desarrollo) | 10–60 ms | 1,3–1,6 s | **1,3–1,7 s** |

La transcripción del usuario aparece en pantalla 0,1–0,2 s después de enviar.

`reasoning_effort` vale para APIs compatibles con OpenAI (NVIDIA, Groq…); con OpenAI usa `'{"reasoning":{"effort":"low"}}'`. Con proveedores reales, el STT y el TTS añaden su propio tiempo: para recortarlo, Groq `whisper-large-v3-turbo` en STT y un modelo TTS rápido (p. ej. `eleven_flash_v2_5`). Pendiente para más adelante: respuesta en streaming (texto y audio por frases) para empezar a hablar antes de tener la réplica completa.

## Frontend (web MVP)

Inertia + Vue 3 con un diseño oscuro (`resources/js`):

| Ruta              | Página                | Qué hace |
|-------------------|-----------------------|----------|
| `/`               | `pages/Welcome.vue`   | Landing para invitados (los usuarios autenticados van al feed) |
| `/login`, `/register` | `pages/auth/*`    | Acceso y registro (con el nivel B1/B2/C1) |
| `/forgot-password`, `/reset-password/{token}` | `pages/auth/*` | Recuperar la contraseña con un enlace por email (válido 60 min; en local llega a Mailpit, http://localhost:8025) |
| `/feed`           | `pages/Feed.vue`      | Noticias del día agrupadas por fecha: título, resumen, vocabulario clave y «Debate this» / «Continue debate»; buscador de noticias |
| `/debates/{id}`   | `pages/Debate.vue`    | Chat de voz con el tutor; «Finish debate» genera el informe de fluidez (muletillas, errores y vocabulario recomendado) |
| `/vocabulary`     | `pages/Vocabulary.vue` | Palabras recomendadas en los informes, con repaso espaciado (Leitner: 1, 2, 4, 8 y 16 días) |
| `/settings`       | `pages/Settings.vue`  | Cambiar el nivel de inglés (también desde la insignia del nivel en la cabecera) |
| `/admin/sources`  | `pages/admin/Sources.vue` | Fuentes RSS (solo administradores) |

El tutor adapta su inglés al nivel del usuario: frases cortas y vocabulario cotidiano en B1, lenguaje natural con algo de expresiones idiomáticas en B2 y registro nativo en C1 (`debate_tutor_levels` en `config/prompts.php`). Un cambio de nivel se aplica desde el siguiente turno. Los resúmenes de las noticias se generan una sola vez por artículo para nivel C1, como pide la especificación; hacerlos por nivel triplicaría el coste de IA del recolector.

La vista de debate graba con `MediaRecorder` y usa la Web Audio API (`AnalyserNode`) para el visualizador del micrófono y de la respuesta. Muestra el estado **Listening → Thinking → Speaking**, sube cada turno a la API con la sesión del navegador y escucha `AIResponseGenerated` / `DebateTurnFailed` en el canal privado con Echo para reproducir la respuesta automáticamente. Se puede interrumpir al tutor pulsando el micro y usar la barra espaciadora como atajo.

## Seleccionar, traducir y guardar palabras

En cualquier texto marcado con `data-selectable` (resúmenes y títulos del feed y del debate, mensajes del debate, informe de fluidez y frases de «Your words") basta con **seleccionar una palabra o expresión** (doble clic, arrastrar o mantener pulsado en el móvil): aparece un panel que la **traduce al momento** según la frase en la que está, con categoría gramatical, definición sencilla, un ejemplo y sinónimos. Desde el panel se puede **escuchar** (síntesis de voz del navegador, sin coste) y **guardar** en «Your words». Las fichas de «Key vocabulary» abren el mismo panel al pulsarlas.

- `POST /api/lookups` (`text`, `context`): explicación del agente `WordExplainer`, adaptada al nivel del usuario. Se cachea 30 días por selección, frase y nivel, así que repetir una consulta no cuesta nada.
- `POST /api/vocabulary` (`word`, `context`): guarda la palabra con su frase, para repasar desde ya, y encola `AnalyzeVocabulary`, que guarda traducción y análisis. Si ya se había traducido, sale de la caché.
- `POST /vocabulary/{id}/analyze`: «Explain» en «Your words», para las palabras que llegaron del informe de fluidez sin análisis.
- Solo palabras o expresiones de hasta 5 palabras y 60 caracteres; 40 consultas por minuto y usuario.

| Variable | Por defecto | Descripción |
|---|---|---|
| `LOOKUP_AI_PROVIDER` / `LOOKUP_AI_MODEL` | `gemini` / por defecto del proveedor | El usuario espera la respuesta: conviene un modelo rápido sin razonamiento (p. ej. el mismo que el tutor) |
| `LOOKUP_TARGET_LANGUAGE` | `Spanish` | Idioma al que se traduce |

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

## Producción: pasarela de IA y proxy inverso

Toda la IA puede ir por una única pasarela compatible con OpenAI (p. ej. [LiteLLM](https://docs.litellm.ai/)) con una sola clave, sin tocar código:

```env
# Texto (tutor, resúmenes, informe): driver openai-compatible → POST /chat/completions
OPENAI_COMPATIBLE_URL=https://pasarela.example/v1
OPENAI_COMPATIBLE_API_KEY=<clave de la pasarela>
# Voz: el driver openai llama a /audio/transcriptions y /audio/speech de la misma pasarela
OPENAI_URL=https://pasarela.example/v1
OPENAI_API_KEY=<la misma clave>

NEWS_AI_PROVIDER=openai-compatible
NEWS_AI_MODEL=general              # obligatorio: openai-compatible no tiene modelo por defecto
DEBATE_LLM_PROVIDER=openai-compatible
DEBATE_LLM_MODEL=chat              # rápido y sin razonamiento: es un turno de voz
DEBATE_EVAL_MODEL=general          # el informe de fluidez no es en tiempo real: puede razonar
DEBATE_STT_PROVIDER=openai
DEBATE_STT_MODEL=stt
DEBATE_TTS_PROVIDER=openai
DEBATE_TTS_MODEL=tts
DEBATE_TTS_VOICE=af_heart          # una voz que acepte el modelo de TTS de la pasarela
```

No uses el driver `openai` para el texto: llama a la Responses API (`/responses`), no a `/chat/completions`. `chat`, `general`, `stt` y `tts` son nombres de modelo de la pasarela; elige modelos que:

- **Tutor:** respondan en 1–2 s sin razonamiento. Un modelo que razona tarda 4–10 s por turno.
- **Informe y resúmenes:** admitan `response_format: json_schema`.
- **STT:** acepten `webm`/`ogg`/`m4a` (lo que graba el navegador) y no «corrijan» al alumno. El informe de fluidez se hace sobre la transcripción: un STT que normaliza la gramática borra justo los errores que hay que señalar.

`DEBATE_EVAL_PROVIDER` / `DEBATE_EVAL_MODEL` dan al informe de fluidez un modelo propio; vacíos, usa el del tutor.

**Detrás de un proxy que termina TLS** (Traefik, nginx…), define `TRUSTED_PROXIES` con su IP o CIDR (varios separados por comas). Sin eso, Laravel genera URLs `http://` (el navegador bloquea los assets por contenido mixto) y todos los usuarios comparten el límite de intentos de login, porque todos llegan con la IP del proxy. Además:

- `APP_URL` debe ser la URL pública `https://…`: con ella se firman las URLs del audio del tutor.
- `VITE_REVERB_HOST`, `VITE_REVERB_PORT=443` y `VITE_REVERB_SCHEME=https` se compilan en `npm run build`, y el proxy debe enrutar `/app` (WebSocket) a Reverb.
- El micrófono (`getUserMedia`) solo funciona en HTTPS con un certificado en el que confíe el navegador.
- `REDIS_QUEUE_RETRY_AFTER` mayor que el timeout del job de voz (120 s), o un turno lento se procesa dos veces.

## Administradores

```bash
./vendor/bin/sail artisan user:admin tu@email.com            # conceder
./vendor/bin/sail artisan user:admin tu@email.com --revoke   # retirar
```

Los administradores gestionan las fuentes de noticias, no tienen límite de turnos de voz (el resto: 20 por minuto) y pueden abrir Horizon fuera de local. El usuario de prueba del seeder es administrador.

## Tests

Los tests usan la base de datos `testing` del contenedor de PostgreSQL (Sail la crea automáticamente). Los proveedores de IA se sustituyen por *fakes* y Scout usa el driver `collection`, así que no hace falta ninguna API key ni tocar el índice de Meilisearch:

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
- [x] Análisis de fluidez post-sesión (`ai_feedback`, Flujo C de `Architecture.md`) y repaso de vocabulario
- [x] Transcripción visible antes de la respuesta, medición y reducción de latencia
- [x] Tutor adaptado al nivel, recuperación de contraseña y búsqueda de noticias
- [ ] Respuesta del tutor en streaming (texto y voz por frases)
- [ ] Pendientes legales: licencia de las fuentes de noticias y RGPD (ver Privacidad)
