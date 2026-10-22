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

El comando `news:fetch` lee los feeds RSS o Atom configurados, descarga el texto de cada artículo nuevo, lo resume con el agente `NewsSummarizer` (Laravel AI SDK) y lo guarda en `news_articles`. Está programado a diario a las 03:00 hora de Madrid (`NEWS_FETCH_TIME` / `SCHEDULE_TIMEZONE`); las fechas se siguen guardando en UTC.

En Sail, el servicio `scheduler` ejecuta `php artisan schedule:work` de forma continua. En producción basta una entrada de cron que lance el scheduler cada minuto:

```cron
* * * * * cd /ruta/a/dailyspeak && php artisan schedule:run >> /dev/null 2>&1
```

Ejecútalo con el mismo usuario que PHP-FPM: cada tarea escribe su salida en `storage/logs/schedule-*.log`.

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
| `NEWS_ALLOW_PRIVATE_HOSTS`    | `false`                                       | Permite feeds y artículos en IP privadas, loopback o reservadas. Solo en desarrollo local |

Las fuentes RSS se guardan en la tabla `news_sources` y se gestionan desde **/admin/sources** (solo administradores): alta con validación (el feed se descarga y se comprueba antes de guardarlo), pausar/activar, eliminar y «Fetch now» para leer una fuente al momento por la cola. La migración crea BBC News – World como fuente inicial; úsala solo en desarrollo, ya que sus condiciones exigen licencia para uso comercial.

El prompt de resumen está en `config/prompts.php`. Los artículos ya guardados no se vuelven a resumir, y las páginas que no son artículos se descartan antes de pagar el resumen: por la URL (`/video`, `/live`, `/audio`, `/podcast`), por tener menos de 3 párrafos, o porque el modelo responde `is_article: false`. El texto de la página va al modelo delimitado y declarado como dato, y el resumen se valida (longitud, sin enlaces, el vocabulario tiene que estar en el texto) antes de guardarlo.

- **Fallos visibles:** una descarga que falla (403, 429, 5xx, tiempo agotado) cuenta como fallo y queda en `last_error` de la fuente, también si otros artículos sí entraron; `last_fetched_at` se escribe al terminar. El comando sale con error si una fuente no entrega nada.
- **Sin pagar dos veces:** un resumen que falla se recuerda 24 h por URL, y tras 3 fallos seguidos se deja la fuente para el día siguiente. Una pasada completa tiene un tope de 1 hora.
- **Enlaces canónicos:** se quitan `utm_*`, `at_*`, `traffic_source`, `fbclid` y el `#fragmento`, para reconocer el mismo artículo aunque el feed cambie el enlace.

## Búsqueda de noticias (Meilisearch)

El buscador del feed (`/feed?q=…`) consulta todas las noticias, no solo las 20 últimas, con Laravel Scout y Meilisearch: tolera erratas («productivty» encuentra «productivity»), exige que aparezcan todas las palabras y ordena los resultados de más reciente a más antiguo. Busca en el título, el vocabulario clave y el resumen, por ese orden de importancia (`config/scout.php`).

- Los artículos se indexan en segundo plano (cola `default`) al crearse, modificarse o borrarse, así que el recolector nunca espera al buscador.
- Si Meilisearch no responde en 3 s (1 s para conectar), el feed sigue funcionando con una búsqueda en PostgreSQL (`ILIKE` sobre título y resumen, con índices trigram de `pg_trgm`) y el error queda en el log.
- La consulta se recorta a 100 caracteres y cada usuario puede buscar 30 veces por minuto.
- Tras cambiar los ajustes del índice: `sail artisan scout:sync-index-settings`. Para reindexar todo: `sail artisan scout:import "App\Models\NewsArticle"`.
- En producción, define `MEILISEARCH_KEY` (la *master key* o una clave con permisos de búsqueda e indexado).
- Limitación: Meilisearch no reduce las palabras a su raíz, así que «geoengineering» no encuentra «geoengineer» (sí lo hace «geoengineer» o el prefijo «geoengin»).

## Debate por voz (API + WebSockets)

Sail levanta tres servicios extra con la misma imagen de la app: `reverb` (servidor WebSocket), `horizon` (workers de la cola `debates`) y `scheduler` (tareas programadas). Tras cambiar código PHP, reinicia los workers con `./vendor/bin/sail restart horizon`.

Flujo de un turno:

1. `POST /api/news-articles/{id}/debates` inicia un debate (o reanuda el activo) y devuelve el canal privado (`debates.{id}`).
2. El cliente se suscribe con Laravel Echo a ese canal (autorización en `/broadcasting/auth`, solo el dueño del debate).
3. `POST /api/debates/{id}/audio` (multipart, campo `audio`: webm, ogg, m4a, mp4, mp3, wav o flac; de 1 KB a 10 MB; campo opcional `turn`, un UUID que elige el cliente) guarda la grabación, encola `ProcessVoiceDebate` y responde `202` con `{"status":"processing","turn":"<uuid>"}`. Un reenvío con el mismo `turn` no se guarda ni se paga otra vez: responde el mismo `202`, o `409` si la primera subida aún se está guardando.
4. El job transcribe el audio (STT) y emite `UserTurnTranscribed` con la transcripción, para que el usuario la vea mientras el tutor piensa. Después pide la réplica al agente `DebateTutor` con los últimos 30 mensajes del debate (LLM), la sintetiza a MP3 (TTS) y guarda ambos mensajes en `debate_messages`.
5. Se emite `AIResponseGenerated` (`ShouldBroadcastNow`) con `transcript`, `audio_url` (URL firmada válida 60 min), `user_message` y `turn`. Cada turno se contesta una sola vez (`reply_to_id` único), aunque el job se reintente. Si no se oye nada, el turno falla tras reintentar o el debate ya terminó, se emite `DebateTurnFailed` con `turn` y `reason` = `no_speech` | `processing_failed` | `debate_ended`; en los dos últimos se borran el mensaje del alumno y su grabación. Los tres eventos llevan el `turn`, y el cliente ignora los de otro turno.

Las rutas de la API usan Sanctum (`auth:sanctum`) y exigen el correo verificado: cookie de sesión para la web o token Bearer para otros clientes. Los turnos se procesan de uno en uno por debate y la subida está limitada a 20 por minuto y 300 al día por usuario (`DEBATE_DAILY_TURNS`; los administradores, sin límite). Si la síntesis falla, el reintento solo repite el TTS: la réplica del tutor se guarda 10 minutos en caché y no se vuelve a pagar el LLM.

| Variable                | Por defecto  | Descripción |
|-------------------------|--------------|-------------|
| `DEBATE_STT_PROVIDER` / `DEBATE_STT_MODEL` | `openai` / `whisper-1` | Transcripción (p. ej. `groq` / `whisper-large-v3-turbo`) |
| `DEBATE_LLM_PROVIDER` / `DEBATE_LLM_MODEL` | `gemini` / por defecto del proveedor | Tutor de debate |
| `DEBATE_LLM_OPTIONS`    | —            | JSON que se añade a cada petición del tutor, p. ej. `'{"reasoning_effort":"low"}'` (ver Latencia) |
| `DEBATE_TTS_PROVIDER` / `DEBATE_TTS_MODEL` | `openai` / por defecto del proveedor | Síntesis de voz (p. ej. `eleven`) |
| `DEBATE_TTS_VOICE`      | `alloy`      | Voz de OpenAI o id de voz de ElevenLabs |
| `DEBATE_TTS_FALLBACK_PROVIDER` / `_MODEL` / `_VOICE` | — / ninguno / `alloy` | Voz de respaldo si la principal falla (otro modelo, con su propio id de voz). Sin modelo, no hay respaldo |
| `DEBATE_STT_TIMEOUT`, `DEBATE_LLM_TIMEOUT`, `DEBATE_TTS_TIMEOUT`, `DEBATE_TTS_FALLBACK_TIMEOUT` | 30, 30, 30, 20 | Segundos por llamada. Su suma debe quedar por debajo de los 120 s del job de voz |
| `DEBATE_EVAL_TIMEOUT`   | 60           | Informe de fluidez; por debajo de los 90 s de `EvaluateDebate` |
| `DEBATE_DAILY_TURNS`    | 300          | Turnos de voz por usuario y día |
| `REVERB_ALLOWED_ORIGINS` | `*`         | Hosts que pueden abrir el WebSocket, separados por comas (p. ej. `dailyspeak.example.com`) |
| `ELEVENLABS_API_KEY` / `GROQ_API_KEY` | — | Solo si se usan esos proveedores |

El system prompt del tutor está en `config/prompts.php` (`debate_tutor`).

### Debate guiado

Todo debate nuevo es guiado, en cualquier nivel y sin interruptor. Cambia el arranque y el feedback, no el turno de
voz:

1. **Resumen del debate**, con estructura fija y en el nivel del alumno: Headline, What happened, Why it matters,
   Background (solo lo que dice el artículo; si no hay, no aparece), Two sides, The debate question y Key words. Se
   genera la primera vez que alguien debate ese artículo en ese nivel (tabla `debate_briefs`) a partir del resumen
   guardado, sin volver a descargar la página, y se reutiliza (`DEBATE_BRIEF_PROVIDER`/`_MODEL`; por defecto los del
   resumen de noticias).
2. **Apertura:** el tutor habla primero, con el encuadre y la pregunta del debate (`TutorOpened`).
3. **Turno del tutor**, 3 frases como mucho: reformula bien lo que dijo el alumno, pone un contrapunto sin dar aún su
   postura y termina con una pregunta; si el alumno pregunta qué significa algo, lo explica y vuelve al debate.
4. **Correcciones solo por escrito:** bajo cada intervención del alumno, hasta 2 de alta confianza, generadas en
   paralelo sin retrasar la voz (`LearnerTurnReviewed`, `DEBATE_REVIEW_PROVIDER`/`_MODEL`). Se descartan con «I didn't
   say that»; las que no, entran en el informe final.

Cada debate guarda su flujo (`debates.flow`): los antiguos, sin guiar, se siguen viendo como eran. El resumen del
debate lo paga la plataforma (se comparte entre usuarios); la revisión de cada turno, quien debate. El análisis del diseño está en `docs/diseno-flujo-debate.md`.

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

En cualquier texto marcado con `data-selectable` (resúmenes y títulos del feed y del debate, mensajes del debate, informe de fluidez y frases de «Your words») basta con **seleccionar una palabra o expresión** (doble clic, arrastrar o mantener pulsado en el móvil): aparece un panel que la **traduce al momento** según la frase en la que está, con categoría gramatical, definición sencilla, un ejemplo y sinónimos. Desde el panel se puede **escuchar** (síntesis de voz del navegador, sin coste) y **guardar** en «Your words». Las fichas de «Key vocabulary» abren el mismo panel al pulsarlas.

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

- Las grabaciones de voz de los usuarios se borran a los **7 días** (`DEBATE_AUDIO_RETENTION_DAYS`) con `debates:prune-recordings`, programado a las 04:00 (hora de Madrid). Las respuestas sintetizadas del tutor, también (`PRIVACY_REPLIES_RETENTION_DAYS`; vacía, la misma retención). También se borran los ficheros huérfanos (turnos sin voz o fallidos, debates que ya no existen). La retención mínima es de 1 día: un valor vacío o 0 no borra grabaciones en curso.
- Las transcripciones y los informes se conservan mientras exista la cuenta (`PRIVACY_TRANSCRIPTS_RETENTION_DAYS=0`, sin caducidad).
- En *Settings*, el usuario puede **descargar sus datos** (JSON con perfil, debates, transcripciones, informes y vocabulario; sin audio) y **borrar su cuenta** con su contraseña: se borran en cascada debates, mensajes, vocabulario, sesiones y tokens, y los ficheros de audio de sus debates.
- Las grabaciones se guardan en disco privado y solo se sirven con URLs firmadas que caducan.
- Las claves de IA de cada usuario se guardan cifradas con `APP_KEY`, nunca se devuelven (solo los 4 últimos
  caracteres) y se borran con la cuenta. El registro de uso (`ai_usage`) no guarda contenido.

Pendiente de ampliar:

- [x] Consentimiento explícito al registrarse (tratamiento de voz y envío a proveedores de IA), con `/privacy`; las cuentas antiguas lo aceptan una vez.
- [ ] Transferencias internacionales: el audio y las transcripciones se envían a OpenAI/Google/NVIDIA; revisar contratos (DPA) y la base legal.
- [x] Borrado de cuenta por el usuario y exportación de sus datos.
- [ ] Decidir un plazo de conservación de las transcripciones (hoy, mientras exista la cuenta).
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
LOOKUP_AI_PROVIDER=openai-compatible
LOOKUP_AI_MODEL=chat               # traducir una selección: el usuario espera, sin razonamiento
```

No uses el driver `openai` para el texto: llama a la Responses API (`/responses`), no a `/chat/completions`. `chat`, `general`, `stt` y `tts` son nombres de modelo de la pasarela; elige modelos que:

- **Tutor:** respondan en 1–2 s sin razonamiento. Un modelo que razona tarda 4–10 s por turno.
- **Informe y resúmenes:** admitan `response_format: json_schema`.
- **Voz de respaldo (opcional):** `DEBATE_TTS_FALLBACK_MODEL` con otro modelo de TTS de la pasarela y `DEBATE_TTS_FALLBACK_VOICE` con una voz suya.
- **STT:** acepten `webm`/`ogg`/`m4a` (lo que graba el navegador) y no «corrijan» al alumno. El informe de fluidez se hace sobre la transcripción: un STT que normaliza la gramática borra justo los errores que hay que señalar.

`DEBATE_EVAL_PROVIDER` / `DEBATE_EVAL_MODEL` dan al informe de fluidez un modelo propio; vacíos, usa el del tutor.

**Detrás de un proxy que termina TLS** (Traefik, nginx…), define `TRUSTED_PROXIES` con su IP o CIDR (varios separados por comas). Sin eso, Laravel genera URLs `http://` (el navegador bloquea los assets por contenido mixto) y todos los usuarios comparten el límite de intentos de login, porque todos llegan con la IP del proxy. Además:

- `APP_URL` debe ser la URL pública `https://…`: con ella se firman las URLs del audio del tutor.
- `VITE_REVERB_HOST`, `VITE_REVERB_PORT=443` y `VITE_REVERB_SCHEME=https` se compilan en `npm run build`, y el proxy debe enrutar `/app` (WebSocket) a Reverb.
- El micrófono (`getUserMedia`) solo funciona en HTTPS con un certificado en el que confíe el navegador.
- `REDIS_QUEUE_RETRY_AFTER` mayor que el timeout del job de voz (120 s), o un turno lento se procesa dos veces. «Fetch now» usa su propia conexión (`news`, `NEWS_QUEUE_RETRY_AFTER`, 1800 s) y su supervisor de Horizon.
- El correo tiene que salir de verdad (`MAIL_*`): sin él nadie puede verificar su cuenta ni recuperar la contraseña.
- `REVERB_ALLOWED_ORIGINS` con el host público.
- Las descargas de feeds y artículos rechazan direcciones privadas o reservadas, y se conectan a la IP ya comprobada (contra DNS rebinding). `NEWS_ALLOW_PRIVATE_HOSTS=true` es solo para desarrollo: en producción lanza una excepción. Como segunda barrera conviene un cortafuegos de salida que no deje al usuario de PHP llegar a la red interna salvo a lo que necesite (la pasarela de IA, el DNS).
- Cabeceras de seguridad en todas las páginas: CSP con nonce de Vite (sin CSP mientras corre `npm run dev`), `frame-ancestors 'none'`, `nosniff`, `Referrer-Policy` y `Permissions-Policy` con el micrófono solo para este sitio. La CSP deja pasar el WebSocket a `VITE_REVERB_HOST`/`VITE_REVERB_PORT`, que deben ser los del build.
- Tras desplegar: `php artisan horizon:terminate` (o reiniciar Horizon) para que cargue el supervisor `debates`, `php artisan scout:sync-index-settings` y, la primera vez, `php artisan news:check-policies`.

**Límites y avisos**

| Variable | Por defecto | Qué hace |
|---|---|---|
| `AI_DAILY_GLOBAL_LIMIT` | `1000` | Llamadas de IA al día que paga la plataforma entre todos los usuarios; lo que cada uno paga con su clave no cuenta, ni los admins. Se cambia en `/admin/limits`. Al agotarse se registra un `critical` |
| `REGISTRATIONS_PER_IP_PER_DAY` | `10` | Cuentas nuevas por IP y día |
| `HORIZON_NOTIFICATIONS_EMAIL` | vacía | Correo para los avisos de Horizon (esperas largas y trabajos fallidos, uno por clase cada 15 min); vacía, sin correo (los fallos se registran igual como `critical`) |
| `HEALTHCHECKS_SCHEDULER_URL` | vacía | Latido del scheduler cada minuto |
| `HEALTHCHECKS_NEWS_FETCH_URL`, `HEALTHCHECKS_PRUNE_RECORDINGS_URL`, `HEALTHCHECKS_HORIZON_SNAPSHOT_URL`, `HEALTHCHECKS_PRUNE_FAILED_URL`, `HEALTHCHECKS_CHECK_POLICIES_URL` | vacías | URL de *ping* de cada tarea programada en un monitor tipo [Healthchecks](https://healthchecks.io): `/start` al empezar, la URL al terminar bien y `/fail` si falla |

Cada tarea programada guarda su salida en `storage/logs/schedule-*.log` (conviene rotarlos) y deja un `critical` en el log si falla. Las contraseñas tienen 12 caracteres como mínimo (y en producción se comprueban contra filtraciones conocidas); el login admite 6 intentos por minuto por email e IP y 30 por hora por email.

## Plataforma multiusuario: cada usuario con su clave (BYOK)

Una instalación sirve a varios usuarios y cada uno paga su uso con su propia clave. El análisis está en
`docs/analisis-multiusuario-byok.md`.

| Lo paga | Qué |
|---|---|
| **El usuario**, con su clave | El turno de voz (STT, tutor y TTS), las traducciones, la revisión de cada turno, el análisis de palabras y el informe final |
| **La plataforma**, con la clave del `.env` o de `/admin/ai` | Los resúmenes de la recogida de noticias y el resumen del debate por artículo y nivel, que se comparten entre usuarios |

- **Settings → AI:** cada usuario guarda la clave de un proveedor (se prueba al guardarla, pidiendo la lista de
  modelos, sin gastar tokens) y elige proveedor y modelo para cada tarea: `conversation`, `writing`, `stt` y `tts`
  (con su voz).
- **Lista cerrada de proveedores** (`App\Services\Ai\ProviderCatalog`): OpenAI (texto, STT y TTS), Groq (texto y
  STT), OpenRouter, Gemini, Anthropic, DeepSeek y Mistral, cada uno con su URL HTTPS fija. No hay URL libre: una
  dirección a elección del usuario sería una salida a la red interna.
- **Sin respaldo silencioso:** quien no tiene clave para una tarea solo usa la de la plataforma si es admin o si un
  admin le activa «Platform AI» en `/admin/users`. Si no, la web le pide la clave y la API responde 409
  `{ code: "ai_not_configured" }`. Mientras quien puede usar la plataforma no elige proveedor para una tarea, esa tarea va por la plataforma.
- **Por llamada:** el cliente de IA se crea donde corre el trabajo (`Ai::build()`), nunca en la configuración global
  del proceso, y los jobs llevan ids, no claves. Así un worker de Horizon no puede usar la clave de un usuario para
  otro.
- **Settings → Usage:** las llamadas del mes por tarea y quién las pagó, las 20 últimas (sin contenido) y un tope
  mensual propio opcional; al llegar, 429 `{ code: "ai_cap_reached" }`.
- **Settings → Sources:** cada usuario oculta las fuentes que no quiere ver en su feed. El catálogo lo gestiona el
  admin.

## Administradores

```bash
./vendor/bin/sail artisan user:admin tu@email.com            # conceder
./vendor/bin/sail artisan user:admin tu@email.com --revoke   # retirar
```

**La primera cuenta es la del dueño:** en una instalación vacía el registro está abierto, la primera cuenta queda como
administradora con el correo ya verificado (puede no haber correo configurado) y después el registro se cierra. Se
abre y se cierra en **/admin** («Registration»); cerrado, `/register` lo dice y `POST /register` responde 403.

El panel **/admin** («Platform» en el menú) reúne: Overview, Users (cuentas con su uso y errores del mes, sin
conversaciones ni claves; dar o quitar admin y «Platform AI», borrar cuentas), AI (conexión, clave y modelos de la
plataforma, con prueba de conexión), Limits, Sources, Privacy (responsable y contacto del aviso) y Jobs (Horizon).

Los administradores gestionan las fuentes de noticias (cada una con su categoría, que se usa para filtrar el feed), no tienen límite de turnos de voz (el resto: 20 por minuto y 300 al día) ni cuentan para el tope diario de IA, y pueden abrir Horizon fuera de local. El usuario de prueba del seeder es administrador (el seeder no hace nada en producción).

**Política de uso de cada fuente.** Al dar de alta una fuente, la app lee el `robots.txt` del feed y de sus artículos y avisa, solo en el panel de administración, si prohíbe a los agentes de IA o el uso para entrenar o resumir con IA, o si el editor tiene condiciones conocidas (`config/source_policies.php`: licencias sin derivados, uso personal no comercial…). El aviso nunca bloquea el alta ni la recogida y los usuarios no lo ven. Cada fuente tiene un botón para volver a comprobarla; `news:check-policies` las revisa todas (programado los lunes a las 05:00). No es asesoría legal: es un recordatorio para quien decide qué fuentes usar.

Las cuentas nuevas reciben un enlace de verificación por correo y no pueden usar la app hasta abrirlo.

## Tests

Los tests usan la base de datos `testing` del contenedor de PostgreSQL (Sail la crea automáticamente). Los proveedores de IA se sustituyen por *fakes* y Scout usa el driver `collection`, así que no hace falta ninguna API key ni tocar el índice de Meilisearch:

```bash
./vendor/bin/sail artisan test
npm test                      # lógica del frontend: turno de voz, grabación, texto, audio, foco (node --test)
```

`.github/workflows/ci.yml` ejecuta en cada push y PR a `main` los tests de PHP (8.4 y 8.5, con PostgreSQL 17), el build y los tests del frontend, `composer audit` y `npm audit`. `phpunit.xml` fija todas las variables que cambian el resultado, para que un `.env` local no lo altere.

## Modelo de datos

| Tabla               | Modelo           | Notas |
|---------------------|------------------|-------|
| `users`             | `User`           | `current_level` → enum `EnglishLevel` (B1, B2, C1) |
| `news_articles`     | `NewsArticle`    | `key_vocabulary` (`jsonb`) → `array`; `source_url` único |
| `debates`           | `Debate`         | `status` → enum `DebateStatus`; `ai_feedback` (`jsonb`) → `array`; `started_at` / `ended_at` |
| `debate_messages`   | `DebateMessage`  | `role` → enum `MessageRole`; solo `created_at`; `reply_to_id` (único) enlaza cada respuesta del tutor con su turno, así un turno se contesta una sola vez |
| `user_vocabularies` | `UserVocabulary` | `mastery_level` 1–5 (CHECK en Postgres); `word` único por usuario |
| `user_ai_keys`      | `UserAiKey`      | `key` cifrada, `last4`, `verified_at`; único por (`user_id`, `provider`) |
| `user_ai_tasks`     | `UserAiTask`     | proveedor (o `platform`), modelo y voz por tarea; único por (`user_id`, `task`) |
| `ai_usage`          | `AiUsage`        | una fila por llamada, sin contenido: tarea, proveedor, modelo, `paid_by`, tokens, `ok`; `user_id` nulo = trabajo común |
| `user_hidden_sources` | `UserHiddenSource` | fuentes que cada usuario oculta de su feed |
| `app_options`       | `AppOption`      | ajustes de la plataforma desde `/admin` (registro, conexión y clave de IA cifrada, privacidad) |

## Roadmap

- [x] Fase 1: Setup (Sail), migraciones y modelos
- [x] Fase 2: Ingesta de noticias (News Harvester)
- [x] Fase 3: Motor de debate por voz y WebSockets (Reverb + Horizon)
- [x] Fase 4: Frontend web MVP
- [x] Análisis de fluidez post-sesión (`ai_feedback`, Flujo C de `Architecture.md`) y repaso de vocabulario
- [x] Transcripción visible antes de la respuesta, medición y reducción de latencia
- [x] Tutor adaptado al nivel, recuperación de contraseña y búsqueda de noticias
- [x] Seleccionar una palabra para traducirla y guardarla; categorías en el feed; feeds Atom; verificación de correo
- [ ] Respuesta del tutor en streaming (texto y voz por frases)
- [ ] Pendientes legales: licencia de las fuentes de noticias y RGPD (ver Privacidad)
