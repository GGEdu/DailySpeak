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
    */

    'stt' => [
        'provider' => env('DEBATE_STT_PROVIDER', 'openai'),
        'model' => env('DEBATE_STT_MODEL', 'whisper-1') ?: null,
        'language' => 'en',
    ],

    'llm' => [
        'provider' => env('DEBATE_LLM_PROVIDER', 'gemini'),
        'model' => env('DEBATE_LLM_MODEL') ?: null,
        'timeout' => 30,
    ],

    'tts' => [
        'provider' => env('DEBATE_TTS_PROVIDER', 'openai'),
        'model' => env('DEBATE_TTS_MODEL') ?: null,
        'voice' => env('DEBATE_TTS_VOICE', 'alloy'),
    ],

];
