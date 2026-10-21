<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Daily AI Budget
    |--------------------------------------------------------------------------
    |
    | Calls to the AI provider per day across all users, counted on the routes
    | that spend them (translations, vocabulary analysis and saves, voice turns).
    | Each user keeps their own limits on top. Admins are exempt. When the budget
    | is used up, the routes answer 429 and a critical entry is logged once a day.
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
