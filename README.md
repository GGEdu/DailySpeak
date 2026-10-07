# DailySpeak
DailySpeak es una aplicación de aprendizaje de idiomas mediante IA diseñada para usuarios con nivel B2/C1. Resuelve el problema de la "latencia cognitiva" y el "síndrome de la página en blanco" utilizando noticias diarias reales (vía RSS) como ancla para debates de voz en tiempo real con un tutor de IA estricto.

La especificación técnica completa está en [`Architecture.md`](Architecture.md).

## Stack

- Laravel 13 (PHP 8.3+) y Laravel AI SDK (`laravel/ai`)
- PostgreSQL (columnas `jsonb`), Redis, Meilisearch y Mailpit vía Laravel Sail

## Puesta en marcha (Laravel Sail)

Requisitos: Docker y PHP 8.3+ con Composer en el host (solo para el primer `composer install`).

```bash
cp .env.example .env
composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate --seed
```

El seeder crea el usuario `test@example.com` (contraseña `password`, nivel C1), cinco noticias, un debate completado con sus mensajes y vocabulario de ejemplo.

Servicios expuestos por defecto:

| Servicio    | URL / puerto            |
|-------------|-------------------------|
| App         | http://localhost        |
| PostgreSQL  | `localhost:5432`        |
| Redis       | `localhost:6379`        |
| Meilisearch | http://localhost:7700   |
| Mailpit     | http://localhost:8025   |

## Ingesta de noticias (News Harvester)

El comando `news:fetch` lee los feeds RSS configurados, descarga el texto de cada artículo nuevo, lo resume con el agente `NewsSummarizer` (Laravel AI SDK) y lo guarda en `news_articles`. Está programado a diario a las 03:00 (zona horaria de la app, UTC por defecto).

```bash
./vendor/bin/sail artisan news:fetch                 # feeds de NEWS_FEEDS
./vendor/bin/sail artisan news:fetch --limit=2       # máx. artículos nuevos por feed
./vendor/bin/sail artisan news:fetch --feed=https://feeds.bbci.co.uk/news/technology/rss.xml
./vendor/bin/sail artisan schedule:work              # ejecuta el scheduler en local
```

Configuración (`.env`):

| Variable                      | Por defecto                                   | Descripción |
|-------------------------------|-----------------------------------------------|-------------|
| `GEMINI_API_KEY` / `OPENAI_API_KEY` | —                                       | Clave del proveedor elegido |
| `NEWS_AI_PROVIDER`            | `gemini`                                      | Proveedor del Laravel AI SDK (`gemini`, `openai`, …) |
| `NEWS_AI_MODEL`               | modelo por defecto del proveedor              | p. ej. `gemini-2.5-flash` o `gpt-4o-mini` |
| `NEWS_FEEDS`                  | `https://feeds.bbci.co.uk/news/world/rss.xml` | Feeds RSS separados por comas |
| `NEWS_MAX_ARTICLES_PER_FEED`  | `5`                                           | Cada artículo nuevo es una llamada al LLM |

El prompt de resumen está en `config/prompts.php`. Los artículos ya guardados no se vuelven a resumir, y los que no tienen texto suficiente en su página (vídeos, directos) se omiten en lugar de resumirse a partir de la entradilla del RSS.

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
- [ ] Fase 3: Motor de debate por voz y WebSockets (Reverb + Horizon)
- [ ] Fase 4: Frontend web MVP
