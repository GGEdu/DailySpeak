<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Daily AI Budget
    |--------------------------------------------------------------------------
    |
    | Calls to the AI provider per day across all users (translations, vocabulary
    | analysis and saves, voice turns, fluency reports). A call is taken right
    | before it is made (App\Services\AiBudget), so refused requests and cached
    | answers cost nothing. Each user keeps their own limits on top. Admins are
    | exempt. When it is used up, those requests are refused (429 in the API, an
    | error on the page) and a critical entry is logged once a day.
    |
    */

    'ai_daily_global' => (int) env('AI_DAILY_GLOBAL_LIMIT', 1000),

    /*
    |--------------------------------------------------------------------------
    | Registrations per IP
    |--------------------------------------------------------------------------
    |
    | Accounts one address may create per day, so a script cannot open accounts
    | in bulk to spend the AI budget.
    |
    */

    'registrations_per_ip_per_day' => (int) env('REGISTRATIONS_PER_IP_PER_DAY', 10),

    /*
    |--------------------------------------------------------------------------
    | Per-user limits (defaults)
    |--------------------------------------------------------------------------
    |
    | The defaults of the limits an admin can change on /admin/limits
    | (App\Services\Limits). A value saved there wins; these apply until one is
    | saved, and again after it is reset. The daily voice-turn cap is in
    | config/debate.php (debate.audio.daily_turns).
    |
    */

    // Voice turns a user may send per minute, each one an STT + LLM + TTS round trip.
    'voice_turns_per_minute' => 20,

    // Word translations and saved words per user: a translation may cost an LLM call.
    'lookups_per_minute' => 40,
    'lookups_per_day' => 500,

    // Feed searches: every pause while typing is a search.
    'feed_searches_per_minute' => 30,

    // Debate reports: each one is a paid evaluation.
    'debate_finishes_per_minute' => 5,

];
