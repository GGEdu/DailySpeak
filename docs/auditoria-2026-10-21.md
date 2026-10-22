# Auditoría de la aplicación · 2026-10-21

**Alcance.** Toda la aplicación en `main` @ `9709832` (PR #1–#4): backend PHP, frontend Vue, base de datos, recolector de noticias, tests y la configuración real del despliegue. Siete revisiones en paralelo, cada una con su especialidad, más una revisión a mano del servidor de producción:

| Revisión | Cómo | Escala de la verificación |
|---|---|---|
| Seguridad | skills `laravel-security` y `security-review` | Todas las rutas de `web.php`, `api.php` y `channels.php`; `isPublic` probado con 30 direcciones; 19 URLs de diferencial de parseo; servidores lentos en `127.0.0.1` para medir timeouts; `composer audit` y `npm audit` |
| Backend | `code-reviewer` | `app/`, `config/`, `routes/` y el código de `vendor` que lo sostiene (7 comportamientos comprobados en el fuente de Laravel, Guzzle y Horizon) |
| Frontend | `typescript-reviewer` | `resources/js/**`; las carreras de `useRecorder` y `useReplyPlayer` reproducidas en Node con mocks de `MediaRecorder`, `getUserMedia` y `Audio`; build medido |
| Fallos silenciosos | `silent-failure-hunter` | Cada `rescue`, `catch` y valor por defecto del flujo de voz, informes, recolector y tareas programadas |
| BD y rendimiento | `database-reviewer` | PostgreSQL 17 con datos sintéticos: 200.000 artículos, 2.000.600 mensajes, 305.000 palabras; `EXPLAIN ANALYZE` y peticiones Inertia reales; migrar, revertir y volver a migrar |
| Recolector | skill `scraping-reviewer` | 17 feeds reales, 44 páginas de artículo pasadas por el lector y el extractor de la app, 9 `robots.txt` |
| Tests | `pr-test-analyzer` | 273 tests PHP y 12 JS; 16 mutaciones dirigidas y 10 sondas de comportamiento en una copia del repo |

**Veredicto: WARNING.** Ningún CRITICAL: no hay pérdida de datos, ni acceso a datos de otro usuario (no hay IDOR en ninguna ruta), ni secretos en el repositorio o su historial. Lo que hay son **12 HIGH** tras quitar duplicados entre revisiones. Se reparten así:

- **El turno de voz**, que puede perderse o pagarse dos veces.
- **El micrófono**, que puede quedarse abierto.
- **El recolector**, que paga resúmenes de vídeos y oculta sus propios fallos.
- **Rendimiento**: el mazo de vocabulario entero en cada repaso, y Meilisearch sin timeout.
- **Ausencia de CI.**

Las baterías pasan enteras (273 PHP y 12 JS), pero **12 de las 16 mutaciones sobreviven**: varios controles de seguridad y de gasto funcionan hoy sin que ningún test avise si alguien los quita.

> Ningún cambio de código en esta auditoría. Los arreglos se agrupan al final en lotes; cada hallazgo lleva un identificador para poder citarlo en el PR que lo cierre.

## Estado al cierre (2026-10-22)

Todo lo de esta auditoría está en `main` y desplegado: 10 lotes en paralelo, una revisión de seguridad y otra de
cohesión sobre el conjunto, sus 12 hallazgos (2 HIGH, 10 MEDIUM) y los menores. **553 tests PHP y 95 JS**, CI en
GitHub (PHP 8.4 y 8.5, frontend), y el despliegue solo acepta commits con el CI en verde.

| Bloque | Estado |
|---|---|
| HIGH V-1 a V-3, N-1 a N-4, D-1, D-2, O-1, T-1, S-1 | ✅ (N-4: aviso en el panel de administración; qué fuentes conservar sigue siendo decisión del dueño) |
| MEDIUM de voz, seguridad, recolector, rendimiento y accesibilidad | ✅ |
| Hallazgos de las dos revisiones finales (CSP de Horizon, tope de IA, login, descompresión, delimitador de prompts, reintento idempotente, Echo, turno fallido, retención en blanco, migración, presupuesto del turno) | ✅ |
| LOW | ✅ casi todos; abiertos: validación del feed en el alta con reintentos (~61 s en el peor caso), `exists()` que no distingue un fichero ausente de uno sin permiso en el purgado |
| Encontrado al desplegar | ✅ una redirección con reintentos rompía la descarga (TypeError) |

Decisiones que siguen abiertas: topes (IA 1.000/día, 10 registros por IP), retención de transcripciones, CAPTCHA y
correos desechables, fuentes con licencia que prohíbe el uso con IA (20 de 22 dan aviso), protección de rama en
GitHub, y las de siempre (crédito de Gemini, `.env.example`, APIs nuevas, streaming, consentimiento RGPD).

## Comprobado en producción

Valores leídos de la configuración en ejecución (nunca del `.env`). Cierran o mitigan varios hallazgos que en el código son solo valores por defecto inseguros:

| Qué | Valor | Hallazgo que mitiga |
|---|---|---|
| `APP_DEBUG` / entorno | `false` / `production` | — |
| Cookie de sesión | `secure`, `http_only`, `same_site=lax` | S-12, S-21 |
| Correo | SMTP real (no `log`) | S-18 |
| `retry_after` de Redis | 180 s (el código trae 90) | V-1 (en parte) |
| `NEWS_ALLOW_PRIVATE_HOSTS` | `false` | S-13 |
| Reverb | escucha en `127.0.0.1`, origen fijado al dominio de la app | S-20 |
| Proxies de confianza | uno, fijo; sin comodín | S-19 |
| Migración de debate activo único | 0 debates cerrados sin informe y con mensajes | D-7 (sin daño real) |
| Contraseña mínima | 8 caracteres (el valor por defecto de Laravel) | S-4 sigue abierto |
| Cortafuegos de salida | ninguno | S-1 tiene impacto real |

## HIGH

| ID | Dónde | Qué pasa | Arreglo |
|---|---|---|---|
| **V-1** | `ProcessVoiceDebate.php:114,119-129,187-204`; `config/broadcasting.php:44`; `config/queue.php:71`; `Debate.vue:27,92-98,180-189` | **El turno de voz puede perderse o ejecutarse dos veces.** Cuatro revisiones llegan aquí por caminos distintos. (1) Si el WebSocket cae durante «Thinking», la respuesta se guarda pero el cliente nunca la ve: a los 90 s sale «taking too long» y el usuario repite el turno, pagando STT, LLM y TTS otra vez. (2) Las dos difusiones son síncronas con el timeout por defecto de Pusher (30 s cada una), que no entra en el presupuesto de 120 s: 30+30+30+20+30+30 = 170 s, el worker mata el job y el reintento no encuentra la réplica en caché (ya se borró) ni comprueba si el turno tiene respuesta, así que crea un segundo mensaje del asistente. (3) El cliente se rinde a los 90 s, antes que el peor caso del servidor (110 s), y su timeout se arma después de la subida. (4) Sin `.env`, `retry_after` es 90 < 120 y el job se reentrega mientras corre. (5) Los eventos no llevan id de turno: una respuesta tardía borra la burbuja del turno siguiente y habla encima. | Guarda de «turno ya respondido» tras `userTurn()` (mejor: columna `reply_to_id` con índice único); `client_options` de Reverb con `timeout` 3 y `connect_timeout` 2; comprobar al arrancar que la suma de timeouts < 120; `retry_after` 180 por defecto. En el cliente: armar el timeout antes del POST y subirlo por encima de 110 s, `router.reload({only:['messages']})` al reconectar, en `visibilitychange` y al vencer el timeout, e id de turno en el 202 y en los eventos. |
| **V-2** | `Debate.vue:138-159`; `useRecorder.js:36-78,112-122` | **El micrófono se queda abierto.** Sin guarda de reentrada mientras `getUserMedia` espera al permiso: dos toques abren dos streams y al enviar solo se cierra uno. Si el usuario sale de la página con el aviso de permiso abierto y luego acepta, el stream se asigna a un componente muerto y el temporizador de 60 s acaba subiendo audio. Un fallo después de `getUserMedia` tampoco cierra las pistas. Reproducido en la simulación: una pista viva en cada caso. | Estado `starting`; `start()` devuelve la promesa en curso; flag `cancelled` comprobado tras cada `await`; `try/catch` con `cleanup()`. |
| **V-3** | `EvaluateDebate.php:143-165` | **El informe vacío se guarda como un éxito.** Si el modelo devuelve JSON inválido, el informe queda en `[]`, la UI culpa al alumno y no hay forma de reintentar (la política exige `ai_feedback === null`). | Lanzar si hubo turnos y todo vino vacío, para que el job reintente y emita `DebateEvaluationFailed`. |
| **N-1** | `NewsHarvester.php:125`; `config/news.php:62`; `NewsSummarizer` | **Se pagan resúmenes de vídeos.** Las páginas de vídeo superan el umbral de 500 caracteres con la transcripción o el texto de la página: 4 de 10 en la muestra (Aeon 1.086, BBC Business 764, BBC World 591, Guardian Society 827). En Aeon, 9 de cada 20 ítems del feed son vídeos. | Descartar por URL (`/video(s)/`, `/live/`, `/audio/`, `/podcast`); exigir al menos 3 párrafos; campo `is_article` en el esquema del resumen. |
| **N-2** | `NewsHarvester.php:64,117-121,186-199`; `FetchNewsSource.php:55,69-74`; `routes/console.php` | **El recolector oculta sus fallos.** Un 403, 429, 5xx o un bloqueo de Cloudflare cuenta como SKIPPED, sin log ni `last_error`, con código de salida 0. `last_error` solo se escribe si no se guardó nada, y se borra en cuanto un ítem entra. `last_fetched_at` se pone al empezar, así que una ejecución que muere a medias parece correcta. El cron va a `/dev/null`. Los tests fijan este comportamiento (`FetchDailyNewsTest.php:98-104,122,291-305`). | Contar los fallos de descarga como FAILED; `last_error` con el resumen cuando `failed > 0`; `last_fetched_at` al terminar; salida del cron a un log; cambiar los tests. |
| **N-3** | `NewsHarvester.php:71-102,131-146,174-176`; `FetchDailyNews.php:29` | **Los resúmenes fallidos se pagan cada noche.** Solo se recuerda «sin texto», no «el LLM falló»: el mismo ítem se reintenta mientras siga en el feed, y cinco seguidos al principio bloquean la fuente. El run programado no pasa `$until`: con el LLM lento son hasta unos 19 min por fuente, en serie. | Recordar el fallo 24 h; cortar la fuente tras 2–3 fallos seguidos; tope de tiempo también en `news:fetch`. |
| **N-4** | Fuentes dadas de alta | **Cumplimiento.** Los `robots.txt` de **Al Jazeera** y **The Guardian** prohíben el uso del contenido para LLM, IA o minería sin permiso. **Aeon** bloquea por nombre a los bots de IA. **The Conversation** es CC BY-ND, sin derivados, y un resumen es un derivado. La app no lee `robots.txt`. | Decisión del dueño: retirar esas fuentes, pedir permiso o usar las API con licencia (Guardian Open Platform). Para un uso personal de prueba el riesgo es bajo; para un producto, no. |
| **D-1** | `VocabularyController.php:21,33-36`; `Vocabulary.vue:37` | **El mazo de vocabulario entero va en cada repaso.** Con el `analysis` jsonb incluido, enviado dos veces (`due` y `words`) y reenviado por el redirect de cada `router.post`. Medido: 300 palabras, 28 ms y 183 KB; 5.300 palabras, 456 ms y 3,36 MB por tarjeta. | `due` limitado a 50; `words` diferido y paginado sin `analysis`; `only: ['due','dueWords']` en los POST. |
| **D-2** | `vendor/laravel/scout/src/ScoutServiceProvider.php:32`; `FeedController.php:112-127`; `Feed.vue:36-44` | **Meilisearch sin timeout.** Scout crea el cliente con un Guzzle sin `timeout` ni `connect_timeout`, y `rescue()` no salta ante un cuelgue. Medido: una llamada colgada seguía viva a los 8 s. Con Meilisearch colgado (no caído), cada pausa al teclear retiene un worker de PHP-FPM. | Registrar `Meilisearch\Client` con un Guzzle de `timeout` 3 y `connect_timeout` 1. |
| **O-1** | `HorizonServiceProvider.php:18-20`; `routes/console.php` | **Nada avisa cuando el trabajo de fondo falla.** Sin notificaciones de Horizon (`LongWaitDetected`, jobs fallidos), sin aviso si el scheduler deja de correr, y el purgado RGPD fallido va a `/dev/null`. | Notificaciones de Horizon; `pingOnSuccess` / `onFailure` hacia un monitor externo en cada tarea programada. |
| **T-1** | Repo sin `.github/workflows` | **No hay CI, y `main` se despliega sola.** Nada ejecuta las baterías: un push a `main` llega a producción con sus migraciones sin haber pasado un test. `npm ci` no ejecuta scripts de instalación (`.npmrc` lleva `ignore-scripts`), pero el build de Vite y `composer install` sí ejecutan código del repo y de sus plugins. | Workflow con PHPUnit, `npm test`, `composer audit` y `npm audit`; protección de rama en `main`; desplegar solo commits en verde. |
| **S-1** | `SafeHttpFetcher.php:87-135`; `DnsHostResolver.php:10-16` | **SSRF por DNS rebinding.** Se valida la IP con una resolución y curl resuelve otra vez al conectar; los reintentos tampoco revalidan. El propio comentario (`:19-22`) lo admite como riesgo residual. Precondición: un admin da de alta un feed hostil o uno legítimo comprometido. El texto de un servicio interno sin autenticación acabaría resumido y visible para todos. Con el despliegue actual sin cortafuegos de salida, el impacto es real. | Fijar en la conexión la IP ya validada (`CURLOPT_RESOLVE`), también en los reintentos; cortafuegos de salida para el usuario de PHP que solo permita la pasarela de IA. |

## MEDIUM

### Voz y debate

| ID | Dónde | Qué pasa | Arreglo |
|---|---|---|---|
| V-4 | `ProcessVoiceDebate.php:197-199,216-226`; `DebateTutor.php:57-67` | Si el LLM responde vacío o fallan las dos voces, el mensaje del usuario queda huérfano y el siguiente prompt lleva dos mensajes de usuario seguidos (sonda). | Borrar o fusionar el huérfano; test de alternancia de roles. |
| V-5 | `Debate.vue:209-224` | Carrera en `speak()`: el `finally` de la reproducción anterior pone `idle` sobre la nueva. El audio suena con la UI en `idle` y tocar el orbe graba encima (reproducido). | Token de generación por llamada. |
| V-6 | `lib/http.js:13-31`; `Debate.vue:183` | `postForm` sin timeout ni `AbortController`: con mala cobertura, la UI queda bloqueada en «Thinking» y se pierde la grabación. | `AbortSignal.timeout(30_000)` y reintento con el mismo blob. |
| V-7 | `useRecorder.js:61`; `useReplyPlayer.js:23-33` | `AudioContext.resume()` en la ruta crítica; en iOS puede quedar pendiente para siempre y dejar la grabación a medio arrancar. | Crear y reanudar el contexto de forma síncrona en el primer toque; no esperarlo para grabar. |
| V-8 | `Debate.vue:73-108,329-332` | «Live» refleja el socket, no la suscripción al canal privado; un fallo de autorización no se ve y se puede grabar sin recibir eventos. | Escuchar `error` y `subscribed` del canal; estado degradado con sondeo. |
| V-9 | `Debate.vue:258-268` | Espacio llama a `preventDefault()` siempre: con el debate terminado ya no desplaza la página; Ctrl/Mayús+Espacio graban; Espacio sobre «Show summary» abre y graba. | Comprobar estado y modificadores antes; ampliar los elementos excluidos. |
| V-10 | `Debate.vue:316,336` | Sin `max-h` por debajo de `lg`: en móvil, con 8 o más turnos, la respuesta nueva y el orbe quedan fuera de pantalla. | `max-h` con `dvh` o `scrollIntoView`. |
| V-11 | `EvaluateDebate.php:99-114,136-140,157-162` | El evaluador manda todos los turnos sin tope (el tutor limita a 30); si falla la BD el reintento repaga el LLM; una palabra de más de 255 caracteres revierte el informe. | Últimos N turnos; cachear el resultado entre intentos; truncar `word`. |

### Seguridad

| ID | Dónde | Qué pasa | Arreglo |
|---|---|---|---|
| S-2 | `SafeHttpFetcher.php:41,121,151-178` | El timeout de 15 s acota las cabeceras, no el cuerpo (`stream => true`). Medido: a 10 KB/s tarda 80 s; a 1 KB/s serían unos 50 min por página, en el run nocturno sin límite. | Reloj total dentro de `readBody`. |
| S-3 | `bootstrap/app.php:18-25`; `NewPasswordController.php:41-51` | Restablecer la contraseña no invalida las sesiones web abiertas: `authenticateSessions()` no está activado. | `$middleware->authenticateSessions();` |
| S-4 | `RegisteredUserController.php:37`; `routes/web.php:22` | Contraseña mínima de 8 sin comprobación de filtraciones; login limitado solo por IP. | `Password::min(12)->uncompromised()`; limitador por email e IP. |
| S-5 | `AppServiceProvider.php:21-31,51-62` | Solo hay topes por cuenta: una granja de cuentas con correos desechables consume la clave de IA. | Tope diario global con corte y alerta; CAPTCHA en el registro. |
| S-6 | Rutas (no hay borrado de cuenta); `PruneVoiceRecordings.php:64` | RGPD: no hay borrado de cuenta; transcripciones, `ai_feedback` y los MP3 de `replies/` se guardan sin límite (el purgado solo cubre grabaciones). | Borrado de cuenta en cascada con ficheros; retención de transcripciones y respuestas. |
| S-7 | `NewsHarvester.php:134-141,156-158`; `config/prompts.php:16-19` | Prompt injection persistente: el texto de la página va al modelo sin delimitar y el resumen se guarda sin validar, visible para todos. Ningún agente tiene herramientas: el impacto es solo texto. | Delimitar el contenido como dato; validar longitud, URLs y que el vocabulario aparezca en el texto. |
| S-8 | `app/Http/Middleware/`; `bootstrap/app.php` | Sin cabeceras de seguridad (CSP, `frame-ancestors`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`), ni en la app ni en el proxy: la página se puede enmarcar, y el orbe de micrófono es un botón de un clic. | Middleware `SecurityHeaders` (CSP con nonce de Vite, `frame-ancestors 'none'`, `microphone=(self)`). |
| S-9 | Sin config de Inertia; logout | El historial de Inertia no se cifra y el logout no llama a `clearHistory()`: en un equipo compartido, «Atrás» tras cerrar sesión enseña transcripciones, vocabulario y email. | `encryptHistory` y `Inertia::clearHistory()` en el logout. |
| S-10 | `config/news.php:85`; `config/debate.php`; `config/lookup.php:19` | Tipado del entorno: `(bool) env('NEWS_ALLOW_PRIVATE_HOSTS')` con `no` u `off` da `true` y apaga la protección SSRF; un `(int) env()` en blanco da 0 (timeouts sin límite, `DEBATE_DAILY_TURNS` deja un turno al día a todos, la retención 0 borra grabaciones en curso). | `filter_var(..., FILTER_VALIDATE_BOOL)`; `max(1, …)` en límites y timeouts. |

### Recolector

| ID | Dónde | Qué pasa | Arreglo |
|---|---|---|---|
| N-5 | `ArticleTextExtractor.php:19` | `not(ancestor::li)` pierde las viñetas del cuerpo (BBC Health perdió las 6 de la norma que explicaba). | Excluir solo las listas de navegación. |
| N-6 | `ArticleTextExtractor.php:64` | Toma el primer `<article>`: en un directo del Guardian (55 artículos) se queda con 356 caracteres y lo cachea 7 días. | El `<article>` con más texto, o `articleBody` de JSON-LD. |
| N-7 | `SafeHttpFetcher.php:121-135` | `retry()` sin backoff y también en 3xx/4xx: un 403 o 429 se reintenta 3 veces en un segundo; cada redirección http→https cuesta dos peticiones. | Reintentar solo en errores de conexión y 5xx, con backoff. |
| N-8 | `FetchDailyNews.php:26-38` | Una excepción de BD o caché fuera del `try` por fuente aborta todas las siguientes. | Ampliar el `try`. |
| N-9 | `config/prompts.php` (`news_summary`, `debate_evaluator`) | El ejemplo JSON del prompt usa comillas simples (inválido); no pide «solo hechos del texto»; no se valida idioma ni párrafos. El evaluador ignora el nivel del alumno y pide siempre vocabulario C1. | Corregir los prompts; pasar `current_level`; interpolar las constantes. |

### Rendimiento y base de datos

| ID | Dónde | Qué pasa | Arreglo |
|---|---|---|---|
| D-3 | `FeedController.php:121-126` | El respaldo `ILIKE` no tiene índice y `q` no tiene longitud máxima ni escapa `%` y `_`: 3,47 s con 200.000 artículos. | GIN trigram en `title` y `summary` (0,07–7,5 ms); `max:100`; `addcslashes`; `throttle` en `/feed`. |
| D-4 | `FeedController.php:60-67` | El filtro por categoría con `whereHas` recorre la tabla para las categorías con pocos artículos (63–86 ms con 200.000). | `whereIn` con los ids de fuente (0,07 ms). |
| D-5 | `DebateSessionController.php:36-45`; `Debate.vue:116` | Mientras se genera el informe se sondea cada 3 s (40 veces) con un `reload` síncrono que enseña la barra de progreso, puede interrumpir un clic y recalcula `article` y `messages`. | Props perezosas; `usePoll` o `async: true`. |
| D-6 | `config/scout.php:145-150` | `scout:sync-index-settings` es un paso manual: si falta, la búsqueda cae a `ILIKE` siempre; un índice vacío devuelve 0 resultados sin respaldo; el orden no es cronológico aunque el README lo diga. | Meterlo en el despliegue; alarma si el número de documentos difiere; `rankingRules` con `sort` primero si se quiere orden por fecha. |
| D-7 | `2026_10_08_120000_add_unique_active_debate_index_to_debates_table.php:17-29` | La deduplicación conservó el debate activo **más antiguo** y cerró los demás sin informe (reproducido). En producción no afectó a nadie; el riesgo es reutilizarla. | Conservar el que tenga más mensajes. |

### Accesibilidad

| ID | Dónde | Qué pasa |
|---|---|---|
| A-1 | `text-ink-500` en 14 usos | Contraste 2,98:1, por debajo de 4,5:1 (WCAG 1.4.3); `ink-400` da 6,0:1. |
| A-2 | Botones de texto `text-xs` | «Cancel», «Remove», «Replay» y chips de 16–22 px de alto (WCAG 2.5.8 pide 24). |
| A-3 | `Vocabulary.vue:33,91-128` | La tarjeta revelada se vuelve a ocultar al guardar una palabra (`watch` por identidad); el foco cae al `body` al revelar o responder. |
| A-4 | `VoiceOrb.vue:25-97` | El canvas dibuja a 60 fps siempre, con un buffer nuevo por fotograma; ninguna animación respeta `prefers-reduced-motion`. |
| A-5 | Layouts | Sin «saltar al contenido» ni anuncio del cambio de página. |

## LOW

**Seguridad**
- S-11: la forma `::ffff:0:a.b.c.d` pasa `isPublic` (añadir `::ffff:0:0:0/96`).
- S-12: `session.secure` sin valor seguro por defecto. Producción lo fija.
- S-13: `allow_private_hosts` no tiene guarda de entorno.
- S-14: el título y el resumen de terceros van en el system prompt del tutor.
- S-15: enumeración de cuentas en el registro.
- S-16: el seeder crea un admin `test@example.com` sin guarda de producción.
- S-17: los mensajes de validación de fuentes son un oráculo de la red interna (solo admins).
- S-18: `MAIL_MAILER=log` por defecto vuelca los enlaces de reset. Producción usa SMTP.
- S-19: se admite `TRUSTED_PROXIES=*`. Producción no lo usa.
- S-20: por defecto Reverb escucha en `0.0.0.0` con origen `*`. Producción lo tiene cerrado.
- S-21: `/broadcasting/auth` sin CSRF. Correcto mientras `SameSite=Lax`.
- S-22: las URLs de audio firmadas duran 60 min y van en el payload.
- S-23: el compose de Sail trae `DB_PASSWORD` `secret` por defecto y publica el puerto.
- S-24: todo está en `devDependencies`, así que `npm audit --omit=dev` no audita nada.
- S-25: `concurrently` y `shell-quote` tienen dos vulnerabilidades críticas, solo en desarrollo y con arreglo disponible.
- S-26: `sanctum.stateful` conserva las entradas `localhost` en producción.

**Voz y debate**
- Un audio de 0 bytes se acepta y se encola, y paga una llamada de STT (`min:1`).
- `finish()` no es atómico.
- El turno descartado por debate terminado no avisa y deja el audio en disco.
- `finishDebate` sin guarda de doble envío.
- Los MP3 de `replies/` nunca se purgan y el borrado en cascada no borra ficheros.
- El correo de verificación es síncrono: con el SMTP caído da 500 con el usuario ya creado.
- Las palabras del evaluador no pasan por `WordLookup::normalise`: «nuance.» y «nuance» quedan como dos fichas.
- `SaveWord` con `firstOrCreate` no atómico: un doble clic da 500.
- Sin `track.onended` ni `recorder.onerror`.
- «Replay» falla tras una hora, cuando caduca la URL firmada.
- `addedWords` solo se muestra con el evento en vivo.

**Recolector**
- `extract()` es código muerto.
- Cortes a mitad de palabra.
- Ruido de pies de página y llamadas a la acción en al menos 10 de 34 artículos.
- Una fecha ausente se convierte en `now()` y las fechas futuras no se recortan.
- `?traffic_source=rss`, `fbclid` y `#fragmento` no se limpian, así que el artículo se duplica.
- `parse_str` reescribe las claves con punto.
- Una URL de más de 2048 caracteres falla en el INSERT después de pagar el LLM.
- Carrera entre `news:fetch` y «Fetch now»: se paga el resumen dos veces y aparece `SQLSTATE[23505]` en `last_error`.
- «Fetch now» responde «Fetching…» aunque el lock haya descartado el despacho.
- `withoutOverlapping()` con 24 h: un SIGKILL salta el run siguiente.

**Base de datos**
- Sesiones en la BD (pasar a Redis, en una base distinta de la caché).
- El purgado recorre toda la PK cada noche (333 ms con 2 M mensajes; un índice parcial lo deja en 20 ms).
- `dueWords` hace un `count` en cada página.
- Un solo supervisor de Horizon para `debates` y `default`.
- `releaseAfter(3)`.
- `debates.news_article_id` con `cascadeOnDelete`: podar artículos borraría debates.
- La clave de caché de las traducciones no lleva versión de prompt ni de modelo.
- `timestamp` sin zona y conexión sin `timezone`.
- Faltan CHECK (`completed` ⇒ `ended_at`, categoría válida).
- `users.email` es único distinguiendo mayúsculas.
- `latest('published_at')` sin desempate.
- `failed_jobs` y los tokens de Sanctum no se podan.

**Frontend**
- El panel de traducción no se abre si se suelta la selección fuera del bloque, y no tiene `max-height`.
- `searchTimer` no se limpia al desmontar.
- `text.js` corta frases en iniciales y en «p.m.».
- `.json().catch(() => ({}))` toma un 2xx que no es JSON por un éxito y lo cachea.
- El feed vacío enseña al usuario `sail artisan news:fetch`.
- El select de categoría del admin no es controlado.
- La caché de explicaciones sobrevive al logout.
- Problemas de ARIA en el orbe, los mensajes y el informe; anillos de foco por debajo de 3:1; los botones de cada fila no llevan el nombre de su elemento.

**Código**
- Echo y pusher-js van en el chunk de entrada: moverlos a `Debate.vue` ahorra 26 kB gzip en todas las demás páginas (medido).
- Inter 700 no se usa (24 kB) y `@laravel/multiplex` tampoco.
- `Debate.vue` tiene 385 líneas y mezcla realtime, máquina de estados, sondeo, teclado y layout.
- La misma limpieza de strings está copiada en tres sitios.
- Comentarios que no son ciertos: «append-only» y «rolling 24 hours».
- El aviso de `DEBATE_LLM_OPTIONS` sale en cada petición.
- Falta `app.config.errorHandler`.

## Tests

| Qué | Resultado |
|---|---|
| PHPUnit | 273/273, 1261 aserciones, ~8,6 s |
| `npm test` | 12/12 |
| Cobertura de líneas | No medible (sin pcov ni xdebug); mapa hecho a mano |
| Mutaciones dirigidas | **12 de 16 sobreviven** |

Mutaciones que la batería no detecta:
- Quitar `verified` de las tres rutas que más gastan.
- Quitar `throttle:6,1` del login.
- Quitar el tope diario de traducciones.
- Que `DnsHostResolver` devuelva siempre una IP pública.
- Añadir `LIBXML_NOENT`, con lo que el contenido de un fichero local acaba en el título.
- Ignorar `max_feed_bytes`.
- Quitar los reintentos del feed.
- Vaciar la migración que verifica a los usuarios existentes.
- Cambiar la política de reintentos del job de voz.
- Quitar `withoutOverlapping` al purgado.

Hoy todos esos controles funcionan: las sondas lo confirman. Lo que falta es que un test avise si alguien los quita.

Por orden de valor:
1. `verified` en las rutas de audio y debate.
2. Los seis `throttle` de autenticación.
3. `DnsHostResolver` real con hosts numéricos, sin red.
4. Tope diario de traducciones (hoy el test compara dos constantes: `assertGreaterThan(40, 500)`).
5. Fijar en `phpunit.xml` las variables que hoy hereda del `.env` local: `REVERB_ALLOWED_ORIGINS`, los `DEBATE_*` y `NEWS_ALLOW_PRIVATE_HOSTS`.
6. Reintentos reales del job de voz con la cola `database`. El test actual confía en la semántica de la cola `sync`, que llama a `failed()` en la primera excepción; en producción, `maxExceptions=2` lo hace en la segunda.

Tests débiles:
- `ExampleTest` con `assertTrue(true)`.
- `ProcessVoiceDebateTest:399`: `catch` vacío sin `fail()`.
- `FetchNewsSourceTest:47`: nunca despacha dos veces.
- `RssFeedReaderTest:86`: no cuenta los intentos.
- `VocabularyTest:89`: no comprueba que la palabra siga existiendo.

En el frontend no hay tests de `http.js`, `time.js`, `friendlyError`, la caché de `explain`, los composables ni la máquina de estados de `Debate.vue`.

## Comprobado y correcto

- **Autorización**:
  - `DebatePolicy` y `UserVocabularyPolicy` en todas las rutas.
  - Canales privados autorizados con la policy.
  - `can:admin` y el gate de Horizon solo admiten admins.
  - `is_admin` fuera de los campos asignables.
- **Cuentas**:
  - Verificación de correo con `hash_equals` sobre una ruta firmada.
  - Respuesta genérica al pedir el reset.
  - Regeneración de sesión al entrar e invalidación al salir.
- **Entradas**:
  - FormRequests en todos los endpoints.
  - El audio se valida por contenido, con un máximo de 10 MB y nombre UUID generado por el servidor.
  - El audio se sirve con firma desde el disco privado, con `no-store` y CSP `sandbox`.
- **Inyección**:
  - Sin `v-html`, `innerHTML`, `{!!` ni SQL crudo.
  - `LIBXML_NONET`.
  - Sin redirecciones abiertas.
  - CSRF en `web` y en la API stateful.
  - Sin CORS.
- **SSRF**:
  - Rangos privados (IPv4, IPv6, mapeadas, NAT64 y CGNAT), hosts numéricos, cada salto de redirección y el tope de tamaño en streaming, todo correcto salvo S-1 y S-2.
  - Todo el HTTP saliente pasa por `SafeHttpFetcher`.
- **Secretos**:
  - Ninguno en los ficheros versionados ni en el historial.
  - Los logs registran ids y tiempos, no contenido.
  - `composer audit` sin avisos.
- **Jobs**:
  - Reutilización del turno por `audio_path`.
  - Réplica cacheada 10 min.
  - Un solo lock por debate compartido entre voz y evaluación.
  - `ShouldBeUnique` con un ciclo de vida correcto.
  - Informe y vocabulario en una sola transacción, con el LLM fuera de ella.
  - Jerarquía de timeouts de «Fetch now» (1500 < 1560 < 1800).
- **Base de datos**:
  - Todas las FK indexadas.
  - El rollback completo funciona.
  - Consultas calientes por debajo de 1 ms con 200.000 artículos y 2 M de mensajes.
  - Sin N+1.
  - En el turno de voz la BD suma menos de 5 ms: la latencia está en las llamadas externas.
- **Frontend**:
  - Limpieza correcta de listeners, `requestAnimationFrame` y canales.
  - `sendTurn` pasa a `thinking` de forma síncrona, así que el temporizador no puede enviar dos veces.
  - Un solo `AudioContext`.
  - Páginas con carga perezosa (el chunk de página mayor ocupa 8 kB gzip).

## No verificado

- El DNS rebinding (S-1) y la prompt injection (S-7) no se reprodujeron en vivo: no había DNS controlado ni pasarela de prueba.
- Los comportamientos de Safari/iOS (V-7) y los de CSS (V-10, A-2) se deducen del código.
- Meilisearch y Redis de producción no se ejercitaron.
- El análisis RGPD es técnico, no jurídico. De las fuentes se leyeron los `robots.txt`; la licencia CC BY-ND de The Conversation no se comprobó en su página de condiciones, y no hubo consulta legal.

## Lotes de arreglo propuestos

| Lote | Cierra | Por qué en este orden |
|---|---|---|
| 1. CI y protección de `main` | T-1 y los tests 1–3 | Todo lo demás se despliega solo; sin CI, cada arreglo puede romper otra cosa sin aviso |
| 2. Turno de voz fiable | V-1 a V-6, V-11, audio de 0 bytes | Es lo que usa el alumno y donde hoy se paga dos veces |
| 3. Recolector honesto | N-1 a N-3, N-5 a N-8, S-1, S-2, S-10, S-11 | Coste diario y fallos invisibles; S-1 y S-2 viven en el mismo fichero |
| 4. Endurecimiento web | S-3 a S-5, S-8, S-9, S-16, D-3 (validación de `q`) | Cambios pequeños y aislados |
| 5. Rendimiento | D-1, D-2, D-4 a D-6, el chunk de Echo | D-1 crece con el uso; D-2 puede tumbar PHP-FPM |
| 6. Avisos | O-1 y los logs sin contexto | Para que el siguiente fallo no sea silencioso |
| 7. RGPD | S-6, purgado de `replies/` | Requisito antes de abrir a más usuarios |
| 8. Accesibilidad | A-1 a A-5 | Lote de CSS y ARIA, sin riesgo funcional |
| Decisión del dueño | N-4 | Qué fuentes conservar |
