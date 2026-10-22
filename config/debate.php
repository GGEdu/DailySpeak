<?php

// Parsed here, not silently defaulted below, so an invalid value can be reported at boot.
$llmOptionsJson = trim((string) env('DEBATE_LLM_OPTIONS', ''));
$llmOptions = $llmOptionsJson === '' ? [] : json_decode($llmOptionsJson, true);

// A timeout, limit or retention period must be a whole number of at least 1. A blank or invalid value falls back
// to the default: (int) would turn a blank into 0, which means "no timeout", "one turn a day" or "delete everything".
$positive = fn (string $key, int $default): int => filter_var(env($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: $default;

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
        // (debates:prune-recordings, daily). The tutor's audio replies go too (config/privacy.php);
        // transcripts and reports are kept while the account exists.
        'retention_days' => $positive('DEBATE_AUDIO_RETENTION_DAYS', 7),
        // Voice turns a regular user may send in each 24-hour window (admins are exempt). The window is a fixed
        // one, counted from the first turn in it (RateLimiter, debate-audio). Each turn costs STT + LLM + TTS,
        // so this is the cap on the AI bill per account. An admin can change it on /admin/limits; this is the default.
        'daily_turns' => $positive('DEBATE_DAILY_TURNS', 300),
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
    | Each user pays for their own speech-to-text, tutor and voice with their own key
    | (App\Services\Ai\AiConnections). The providers and models here are the platform's,
    | used only by the calls the platform pays for and by a user who picked "platform"
    | (App\Services\Ai\PlatformAi). An empty model uses the provider's default.
    |
    | Timeouts are in seconds, per request. Budget: a voice turn runs STT, then the
    | tutor, then TTS inside ProcessVoiceDebate, whose $timeout is 120. Its two
    | broadcasts (config/broadcasting.php, 3 s each) and its own overhead count too, so
    | see ProcessVoiceDebate::OVERHEAD_SECONDS. The fluency report runs DebateEvaluator
    | inside EvaluateDebate, whose $timeout is 90, so its timeout must stay below that.
    | The defaults add up to 99 for a voice turn (30 + 30 + 30 for the three calls, 3 + 3
    | for the broadcasts and 3 of overhead) and 60 for the report.
    |
    */

    'stt' => [
        'provider' => env('DEBATE_STT_PROVIDER', 'openai'),
        'model' => env('DEBATE_STT_MODEL', 'whisper-1') ?: null,
        'language' => 'en',
        'timeout' => $positive('DEBATE_STT_TIMEOUT', 30),
    ],

    'llm' => [
        'provider' => env('DEBATE_LLM_PROVIDER', 'gemini'),
        'model' => env('DEBATE_LLM_MODEL') ?: null,
        'timeout' => $positive('DEBATE_LLM_TIMEOUT', 30),
        // Provider-specific request options (JSON) merged into every tutor request. A lower
        // reasoning effort answers several times faster: {"reasoning_effort":"low"} for NVIDIA,
        // Groq and other OpenAI-compatible APIs, {"reasoning":{"effort":"low"}} for OpenAI.
        'options' => is_array($llmOptions) ? $llmOptions : [],
        // False when DEBATE_LLM_OPTIONS is not a JSON object: the options are ignored and a warning is logged.
        'options_valid' => is_array($llmOptions),
        // Only the most recent messages are sent as history, so long debates stay fast and cheap.
        'history_messages' => 30,
    ],

    // Post-session fluency report: the learner's own connection for writing (the model they pick), with this timeout.
    'evaluator' => [
        'timeout' => $positive('DEBATE_EVAL_TIMEOUT', 60),
        // The report reads the learner's latest turns only: at most this many, and at most this many characters
        // in all. Long debates stay cheap, and a retry of a long one cannot grow the bill.
        'max_turns' => 30,
        'max_characters' => 10000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Guided Debate
    |--------------------------------------------------------------------------
    |
    | Every new debate is guided, at every level: it starts from a brief of the article (headline, what happened, why
    | it matters, the two sides and the debate question), opens with that question, and reviews the learner's turns in
    | writing. A debate keeps the flow it started with.
    |
    */

    'guided' => [
        // The brief is written once per article and level, in a single call. An empty provider uses the news
        // summariser's (config/news.php, ai), and so does an empty model when the provider is also empty; a provider
        // of its own never inherits the news model.
        'brief' => [
            'provider' => env('DEBATE_BRIEF_PROVIDER') ?: null,
            'model' => env('DEBATE_BRIEF_MODEL') ?: null,
            'timeout' => $positive('DEBATE_BRIEF_TIMEOUT', 60),
        ],

        // The written corrections of one learner turn: a short call that runs beside the tutor's answer, on the learner's
        // own connection for conversation.
        'review' => [
            'timeout' => $positive('DEBATE_REVIEW_TIMEOUT', 30),
        ],
    ],

    'tts' => [
        'provider' => env('DEBATE_TTS_PROVIDER', 'openai'),
        'model' => env('DEBATE_TTS_MODEL') ?: null,
        'voice' => env('DEBATE_TTS_VOICE', 'alloy'),
        'timeout' => $positive('DEBATE_TTS_TIMEOUT', 30),
    ],

];
