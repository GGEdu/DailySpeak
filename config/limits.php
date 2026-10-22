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

];
