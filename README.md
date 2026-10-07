# DailySpeak
DailySpeak es una aplicación de aprendizaje de idiomas mediante IA diseñada para usuarios con nivel B2/C1. Resuelve el problema de la "latencia cognitiva" y el "síndrome de la página en blanco" utilizando noticias diarias reales (vía RSS) como ancla para debates de voz en tiempo real con un tutor de IA estricto.

La especificación técnica completa está en [`Architecture.md`](Architecture.md).

## Stack

- Laravel 13 (PHP 8.3+)
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
- [ ] Fase 2: Ingesta de noticias (News Harvester)
- [ ] Fase 3: Motor de debate por voz y WebSockets (Reverb + Horizon)
- [ ] Fase 4: Frontend web MVP
