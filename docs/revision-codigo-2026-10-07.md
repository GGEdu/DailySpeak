# Revisión de código · 2026-10-07

**Alcance.** Los 16 commits de `claude/lucid-cori-otklcs` frente a `main` (Fases 1–4, proveedor NVIDIA, administración, fuentes, RGPD y B6–B12): 180 ficheros. Revisión estática del código de la app, `config/`, rutas, migraciones, frontend y tests, más el código de `laravel/ai` v1.1.0 (el que fija `composer.lock`). La batería pasa entera: **151/151** con los tests añadidos en esta rama.

**Veredicto: WARNING.** Ningún CRITICAL. Los dos HIGH eran de despliegue detrás de un proxy y están resueltos (uno en código, otro en configuración). Quedan abiertos varios MEDIUM de fiabilidad y coste.

## Estado al cierre del 2026-10-07

Todos los hallazgos abiertos se trabajaron el mismo día, en ramas separadas, y se integraron con tests (273 PHP + 12 JS en verde):

| # | Estado | Cómo |
|---|---|---|
| 4 | ✅ | «Fetch now» despacha `FetchNewsSource` en una cola y un supervisor propios (`news`): job 1500 s < supervisor 1560 s < `retry_after` 1800 s. |
| 5 | ✅ | `NewsHarvester`: el cupo cuenta artículos **guardados**, con un tope de `3 × cupo` descargas; los ítems sin texto se recuerdan 7 días. |
| 6 | ✅ | `SafeHttpFetcher`: solo http/https, rechaza IPs privadas o reservadas (cada redirección se valida), límite de tamaño mientras descarga. Riesgo residual: DNS rebinding entre la comprobación y la descarga. |
| 7 | ✅ | El borrado RGPD solo anula `audio_path` si el fichero ya no está; si no, aviso y código de salida 1. |
| 8 | ✅ | Timeouts configurables con presupuesto documentado y testeado. |
| 9 | ⏳ | `.env.example` sigue sin corregir (permisos del entorno de trabajo); el README documenta las variables. |
| 10 | ✅ | 300 turnos/día por usuario y **verificación de correo** obligatoria antes de usar la app. |
| 13-17 | ✅ | Informe único por debate, índice único parcial de debate activo, el job de voz ignora debates terminados, el reintento de TTS reutiliza la réplica, el vocabulario y el informe en una transacción. |
| 18 | ❎ | **No se reproduce**: en Laravel 13 `/broadcasting/auth` no comprueba CSRF. Sin cambios. |
| 19-22 | ✅ | Aviso de log con `DEBATE_LLM_OPTIONS` inválido; prioridad real de la cola `debates` y `horizon:snapshot`; `REVERB_ALLOWED_ORIGINS`; mensajes del micrófono (HTTPS, permiso denegado, sin soporte). |
| 23 | ⏳ | La migración sigue sembrando BBC World. Las licencias de las fuentes son una decisión pendiente. |

Además: feeds **Atom**, **categorías** con filtro en el feed, **voz de respaldo** del tutor, cabecera móvil y foco por teclado en el panel de traducción.

## Resueltos en la rama `deploy/litellm-homelab`

| # | Sev. | Qué | Cómo |
|---|---|---|---|
| 1 | HIGH | Sin `trustProxies`. Detrás de un proxy que termina TLS, Laravel genera URLs `http://` (el navegador bloquea los assets) y `$request->ip()` es la del proxy, así que los `throttle:6,1` de login, registro y recuperación **comparten un único cupo para todos los usuarios**. | `TRUSTED_PROXIES` (IP/CIDR o `*`) leído en `AppServiceProvider`. Tests en `tests/Feature/Web/TrustedProxiesTest.php`. |
| — | — | El informe de fluidez usaba el modelo del tutor, que en voz debe ser rápido y sin razonamiento. | `DEBATE_EVAL_PROVIDER` / `DEBATE_EVAL_MODEL`; vacíos, sigue usando el del tutor. Tests en `tests/Feature/Ai/DebateEvaluatorTest.php`. |

## Resueltos por configuración en el despliegue

| # | Sev. | Qué | Valor |
|---|---|---|---|
| 2 | HIGH | `.env.example` trae valores de desarrollo (`APP_DEBUG=true`, secretos de Reverb publicados) y el seeder crea un admin `test@example.com` / `password`. | `.env` de producción generado aparte, secretos nuevos, `migrate` **sin** `--seed`. |
| 3 | MEDIUM | `retry_after` (90 s por defecto) menor que el timeout del job de voz (120 s): un turno lento se reentrega y se paga dos veces. | `REDIS_QUEUE_RETRY_AFTER=180`. |
| 9 | MEDIUM | Con `openai-compatible` el modelo no puede ir vacío (no hay modelo por defecto), aunque `.env.example` dice lo contrario. | Modelos explícitos en todas las variables. |
| 11 | MEDIUM | El `audio_url` del evento se construye en el worker con `APP_URL`. | `APP_URL` con la URL pública `https://`. |
| 12 | MEDIUM | Web y Horizon comparten el disco `local` de audio. | Mismo host y mismo usuario para PHP-FPM, Horizon y Reverb. |

## Abiertos

| # | Sev. | Fichero | Qué pasa |
|---|---|---|---|
| 4 | MEDIUM | `Admin/NewsSourceController.php:72` | «Fetch now» encola `news:fetch` en `default`, con el timeout de 120 s de Horizon. Cinco artículos (descarga 15 s + LLM 60 s cada uno) no caben: el worker muere a mitad y el admin no ve error. Con el scheduler no pasa. |
| 5 | MEDIUM | `FetchDailyNews.php:54-58,102-107` | `take($limit)` se aplica antes de descartar los ítems sin texto (vídeos, directos): si los cinco primeros son vídeos, el feed no produce nada mientras sigan en el RSS. Los fallos del LLM no se registran. |
| 6 | MEDIUM | `StoreNewsSourceRequest.php`, `RssFeedReader.php`, `ArticleTextExtractor.php` | Sin protección SSRF: el feed y los `<link>` de cada ítem se descargan sin restringir destino, siguiendo redirecciones y sin límite de tamaño. Un feed puede apuntar a servicios de la red interna y su texto acabaría resumido y visible en el feed. Solo los administradores dan de alta fuentes. |
| 7 | MEDIUM | `PruneVoiceRecordings.php:35-49` | El disco local tiene `throw=false`: si `delete()` falla, el comando pone `audio_path=null` igualmente y el audio se queda en disco sin rastro (incumple la retención RGPD de 7 días en silencio). |
| 8 | MEDIUM | `ProcessVoiceDebate.php:150-153,185-188` | Timeouts de STT y TTS fijos en 30 s (los del SDK) y del evaluador en 60 s. |
| 10 | MEDIUM | `RegisteredUserController.php`, `User.php` | Registro abierto sin verificación de correo y sin tope diario de turnos: el coste de IA solo lo frena el presupuesto de la clave. |
| 13 | LOW | `DebateSessionController.php:51-57` | `EvaluateDebate` no es único: un doble clic en «Finish» paga dos informes. |
| 14 | LOW | `User.php:69-75` | `firstOrCreate` sin índice único: un doble envío crea dos debates activos para el mismo artículo. |
| 15 | LOW | `ProcessVoiceDebate.php:87-126` | El job no comprueba si el debate ya terminó. |
| 16 | LOW | `ProcessVoiceDebate.php:102-103,171` | Si falla el TTS, el reintento vuelve a llamar al LLM (doble coste, respuesta distinta). |
| 17 | LOW | `EvaluateDebate.php:77-79` | En un reintento, `added_words` llega vacío. |
| 18 | LOW | `resources/js/app.js` | Echo lee el token CSRF al crearse; tras logout y login sin recarga, `/broadcasting/auth` da 419. |
| 19 | LOW | `config/debate.php:75` | Un JSON inválido en `DEBATE_LLM_OPTIONS` se ignora sin aviso. |
| 20 | LOW | `config/horizon.php:204-205` | `balance=auto` no prioriza `debates` sobre `default` (el comentario dice lo contrario) y `horizon:snapshot` no está programado. |
| 21 | LOW | `config/reverb.php:85` | `allowed_origins` = `['*']`. Riesgo bajo: el canal es privado. |
| 22 | LOW | `useRecorder.js:22-28` | Servido por `http`, el error dice «not supported in this browser» en vez de «hace falta HTTPS». |
| 23 | LOW | `create_news_sources_table.php:26` | La migración siembra BBC News en cualquier entorno; su licencia no permite uso comercial. |

## Comprobado y correcto

- Canales privados: solo el dueño del debate (`Broadcast::channel(Debate::class, …)`; 403 si no existe).
- Subida de audio: `auth:sanctum`, política `speak`, 20 por minuto por usuario, validación por contenido, ruta sin entrada del usuario y CSP restrictiva al servirla.
- Sin `v-html`; `source_url` solo `http`/`https`; `is_admin` no asignable en masa.
- Eventos de broadcast en `rescue()` e indexación de Scout en cola: un Reverb o un Meilisearch caídos no rompen el turno ni el recolector.

## Proveedores de IA en `laravel/ai` v1.1.0

| Función | Driver `openai` | Driver `openai-compatible` |
|---|---|---|
| Texto | `POST /responses` (Responses API) | `POST /chat/completions` |
| STT | `POST /audio/transcriptions` | `POST /audio/transcriptions` |
| TTS | `POST /audio/speech`, `response_format=mp3` fijo | no existe |

Por eso, con una pasarela tipo LiteLLM, el texto va por `openai-compatible` y la voz por `openai` con `OPENAI_URL` apuntando a la pasarela (ver README, «Producción»).
