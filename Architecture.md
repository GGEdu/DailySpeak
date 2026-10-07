# Documento de Arquitectura y Especificaciones Técnicas: Proyecto "DailySpeak"

Este documento actúa como la memoria técnica y el *roadmap* de desarrollo para que un agente de IA autónomo (o un desarrollador humano) pueda inicializar, programar y desplegar la plataforma **DailySpeak**.

## 1. Visión General del Producto

**DailySpeak** es una aplicación de aprendizaje de idiomas mediante IA diseñada para usuarios con nivel B2/C1. Resuelve el problema de la "latencia cognitiva" y el "síndrome de la página en blanco" utilizando noticias diarias reales (vía RSS) como ancla para debates de voz en tiempo real con un tutor de IA estricto.

## 2. Stack Tecnológico (Infraestructura y Herramientas)

El sistema está diseñado para ser desplegado mediante contenedores (Docker/Laravel Sail en desarrollo; Proxmox/LXC en producción).

* **Backend & Core:** Laravel 13 (PHP 8.3+). Se aprovechará la capa de IA nativa del framework.
* **Base de Datos:** PostgreSQL. Crucial para utilizar campos `JSONB` en el almacenamiento de feedback y vocabularios, y búsquedas eficientes.
* **Gestor de Colas:** Redis + Laravel Horizon. Para procesar el STT, LLM y TTS de forma asíncrona.
* **WebSockets (Real-time):** Laravel Reverb. Para la transmisión bidireccional del estado del debate y los archivos de audio sintetizados.
* **Frontend (Fase 1 - Web MVP):** Laravel + Vue.js/React (vía Inertia.js) o Livewire/Blade para una iteración rápida.
* **Frontend (Fase 2 - Móvil):** Flutter (Dart) para acceso nativo al micrófono en segundo plano y WebSockets.
* **Proveedores de IA:**
* *STT (Speech-to-Text):* Whisper API (OpenAI o Groq para latencia ultra-baja).
* *LLM (Cerebro):* Gemini 2.5 Flash o GPT-4o Mini (procesamiento de texto y lógica de debate).
* *TTS (Text-to-Speech):* ElevenLabs API o OpenAI TTS (voces hiperrealistas).



---

## 3. Esquema de Base de Datos (Modelos de Eloquent)

El agente debe crear las siguientes migraciones y modelos:

1. **`users`**: `id`, `name`, `email`, `password`, `current_level` (enum: B1, B2, C1), `created_at`, `updated_at`.
2. **`news_articles`**: `id`, `title`, `source_url`, `summary` (text), `key_vocabulary` (jsonb), `published_at` (timestamp), `created_at`, `updated_at`.
3. **`debates`**: `id`, `user_id` (FK), `news_article_id` (FK), `status` (enum: active, completed), `ai_feedback` (jsonb - reporte de fluidez), `started_at`, `ended_at`.
4. **`debate_messages`**: `id`, `debate_id` (FK), `role` (enum: user, assistant), `transcript` (text), `audio_path` (string, nullable), `created_at`.
5. **`user_vocabularies`**: `id`, `user_id` (FK), `word` (string), `mastery_level` (int 1-5), `next_review_at` (timestamp - para Spaced Repetition).

---

## 4. Flujos de Trabajo Centrales (Workflows)

### Flujo A: Ingesta Automática de Noticias (News Harvester)

**Implementación:** `app/Console/Commands/FetchDailyNews.php` programado en el `routes/console.php` a las 03:00 AM.

1. Leer RSS feeds configurados (ej. BBC, NPR).
2. Extraer el texto limpio (usando paquetes como `dg/rss-php`).
3. Llamar a la Facade de IA nativa de Laravel para resumir:
*Prompt:* `"Resume esta noticia en 3 párrafos para un estudiante de inglés C1. Extrae 5 términos de vocabulario avanzado y devuelve todo estrictamente en formato JSON: {summary: '', vocabulary: ['word1', 'word2']}"`
4. Guardar en `news_articles`.

### Flujo B: Sesión de Debate por Voz (WebSocket + Jobs)

**Implementación:** Endpoint de subida de audio (REST) o streaming directo por WS. `app/Jobs/ProcessVoiceDebate.php`.

1. El frontend graba un chunk de audio del usuario y lo envía al backend.
2. Se encola `ProcessVoiceDebate`.
3. **STT:** El worker usa Whisper para transcribir el audio a texto. Guarda en `debate_messages`.
4. **LLM:** Se construye el historial de mensajes del debate. Se inyecta el `System Prompt` (ver sección 5) y se envía a Gemini/OpenAI. Guarda la respuesta en `debate_messages`.
5. **TTS:** La respuesta de texto de la IA se envía a ElevenLabs/OpenAI TTS para generar un `.mp3`.
6. **Broadcast:** Se dispara `AIResponseGenerated::dispatch($debate, $audioUrl, $transcript)`. Laravel Reverb lo envía al frontend para su reproducción inmediata.

### Flujo C: Análisis de Fluidez Post-Sesión

**Implementación:** Botón "Finalizar Debate" en el cliente.

1. Se recopilan todos los mensajes del usuario en el debate actual.
2. Se envían al LLM con el prompt de evaluación.
3. Se guarda el resultado en el campo `ai_feedback` (JSON) del modelo `Debate`.
4. Se parsean las palabras recomendadas y se insertan/actualizan en `user_vocabularies` para futuras sesiones.

---

## 5. Prompts del Sistema (System Instructions)

El agente debe implementar estos prompts en clases de servicio o archivos de configuración (`config/prompts.php`):

**Prompt del Tutor de Debate (Para la conversación en vivo):**

> "You are an expert native English tutor and debate partner. We are discussing the following news article: [INSERT_SUMMARY]. The user is an advanced (C1) English speaker.
> Rules:
> 1. Do NOT break character. Act as a peer discussing the news.
> 2. Push back on the user's opinions, ask probing questions, and demand deep explanations.
> 3. Keep your responses concise (2-3 sentences max) to maintain a natural voice conversation flow.
> 4. Do NOT correct grammar during the conversation. Just focus on the debate."
> 
> 

**Prompt del Evaluador (Post-sesión):**

> "Analyze the following transcript of an English learner's side of a debate.
> 1. Identify their most frequently overused basic words (crutch words).
> 2. Identify syntactic errors or direct translations from their native language.
> 3. Provide 3 specific C1-level vocabulary words they should have used instead.
> Return the output strictly as a JSON object matching this structure:
> { 'crutch_words': [], 'grammar_errors': [{ 'error': '', 'correction': '' }], 'recommended_vocabulary': [{ 'word': '', 'context': '' }] }"
> 
> 

---

## 6. Instrucciones de Ejecución para el Agente de Desarrollo

Para construir el MVP, el agente debe seguir las siguientes fases de forma secuencial:

### Fase 1: Infraestructura y Modelos

1. Ejecutar `laravel new dailyspeak` (o configurar Sail con PostgreSQL y Redis).
2. Crear migraciones para las 5 tablas descritas en la Sección 3.
3. Crear los Modelos de Eloquent, aplicando *casts* para las columnas `jsonb` y definiendo las relaciones (hasMany, belongsTo).

### Fase 2: El Motor de Noticias (News Harvester)

1. Instalar un parser de RSS.
2. Crear un `Command` (`FetchDailyNews`) que recorra un feed de prueba.
3. Integrar la llamada al LLM (vía abstracción nativa de Laravel 13 o SaloonPHP) utilizando el prompt de resumen.
4. Programar el comando en el Scheduler de Laravel.

### Fase 3: Lógica Conversacional y Audio

1. Instalar y configurar Laravel Reverb y Laravel Horizon.
2. Crear los controladores API para iniciar un `Debate` asociado a un `NewsArticle`.
3. Crear el Job `ProcessVoiceDebate` (como se definió en conversaciones previas) que ejecute las llamadas secuenciales: Transcripción (STT) $\rightarrow$ Chat (LLM) $\rightarrow$ Síntesis (TTS).
4. Configurar el evento de Broadcast para enviar el audio sintetizado al frontend mediante WebSockets.

### Fase 4: Frontend MVP

1. Diseñar un dashboard web limpio, con modo oscuro, reflejando el *branding* discutido (DailySpeak, "Stop studying English. Start debating the world").
2. Implementar la interfaz con 2 vistas principales:
* **Feed View:** Lista de noticias del día (Título, Resumen, Vocabulario Clave).
* **Debate View:** Interfaz limpia con un botón de grabación ("Hold to Speak" o VAD continuo), visualizador de ondas de audio y transcripción en tiempo real.


3. Conectar el cliente de Reverb (Echo) para escuchar los eventos de respuesta de la IA.