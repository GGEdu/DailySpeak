<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Voice turns are processed on their own queue so Horizon can give them
    | priority over background work such as the news harvester.
    |
    */

    'queue' => env('DEBATE_QUEUE', 'debates'),

    /*
    |--------------------------------------------------------------------------
    | Audio Storage
    |--------------------------------------------------------------------------
    |
    | Both the user's recordings and the tutor's synthesised replies are kept
    | on this disk. Clients receive temporary signed URLs to play them back.
    |
    */

    'audio' => [
        'disk' => env('DEBATE_AUDIO_DISK', 'local'),
        'url_ttl_minutes' => 60,
        // GDPR data minimisation: users' recordings are deleted after this many days
        // (debates:prune-recordings, daily). Transcripts and tutor replies are kept.
        'retention_days' => (int) env('DEBATE_AUDIO_RETENTION_DAYS', 7),
        'max_upload_kilobytes' => 10 * 1024,
        // Accepted upload format (extension guessed from the file contents) => extension it is
        // stored with. Whisper picks its decoder from the file name, so e.g. "audio/webm"
        // recordings (guessed as "weba") must be stored as ".webm".
        'formats' => [
            'webm' => 'webm',
            'weba' => 'webm',
            'ogg' => 'ogg',
            'oga' => 'ogg',
            'mp3' => 'mp3',
            'mpga' => 'mp3',
            'm4a' => 'm4a',
            'mp4' => 'mp4',
            'wav' => 'wav',
            'flac' => 'flac',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Speech-to-Text, Tutor and Text-to-Speech Models
    |--------------------------------------------------------------------------
    |
    | Providers are defined by the Laravel AI SDK. An empty model uses the
    | provider's default. Examples: STT "groq" + "whisper-large-v3-turbo",
    | TTS "eleven" with an ElevenLabs voice id.
    |
    | Timeouts are in seconds, per request. Budget: a voice turn runs STT, then the
    | tutor, then TTS inside ProcessVoiceDebate, whose $timeout is 120, so the three
    | timeouts must add up to less than that. The fluency report runs DebateEvaluator
    | inside EvaluateDebate, whose $timeout is 90, so its timeout must stay below that.
    | The defaults add up to 90 for a voice turn and 60 for the report.
    |
    */

    'stt' => [
        'provider' => env('DEBATE_STT_PROVIDER', 'openai'),
        'model' => env('DEBATE_STT_MODEL', 'whisper-1') ?: null,
        'language' => 'en',
        'timeout' => (int) env('DEBATE_STT_TIMEOUT', 30),
    ],

    'llm' => [
        'provider' => env('DEBATE_LLM_PROVIDER', 'gemini'),
        'model' => env('DEBATE_LLM_MODEL') ?: null,
        'timeout' => (int) env('DEBATE_LLM_TIMEOUT', 30),
        // Provider-specific request options (JSON) merged into every tutor request. A lower
        // reasoning effort answers several times faster: {"reasoning_effort":"low"} for NVIDIA,
        // Groq and other OpenAI-compatible APIs, {"reasoning":{"effort":"low"}} for OpenAI.
        'options' => json_decode((string) env('DEBATE_LLM_OPTIONS'), true) ?: [],
        // Only the most recent messages are sent as history, so long debates stay fast and cheap.
        'history_messages' => 30,
    ],

    // Post-session fluency report. It is not real time, so it can use a slower model that
    // reasons while the tutor uses a fast one. Empty values fall back to the tutor's provider
    // and model; a provider of its own never inherits the tutor's model.
    'evaluator' => [
        'provider' => env('DEBATE_EVAL_PROVIDER') ?: null,
        'model' => env('DEBATE_EVAL_MODEL') ?: null,
        'timeout' => (int) env('DEBATE_EVAL_TIMEOUT', 60),
    ],

    'tts' => [
        'provider' => env('DEBATE_TTS_PROVIDER', 'openai'),
        'model' => env('DEBATE_TTS_MODEL') ?: null,
        'voice' => env('DEBATE_TTS_VOICE', 'alloy'),
        'timeout' => (int) env('DEBATE_TTS_TIMEOUT', 30),
    ],

];
