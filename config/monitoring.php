<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Notifications
    |--------------------------------------------------------------------------
    |
    | Address that receives Horizon's mails: long queue waits and failed jobs.
    | Leave it empty to turn the mail off. Failed jobs are still logged at
    | critical level either way.
    |
    */

    'horizon' => [
        'notifications_email' => env('HORIZON_NOTIFICATIONS_EMAIL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Healthchecks Pings
    |--------------------------------------------------------------------------
    |
    | Ping URLs of the checks in a Healthchecks monitor. A scheduled task pings
    | its URL with /start when it begins, with nothing appended when it
    | succeeds, and with /fail when it fails. The scheduler heartbeat pings its
    | URL once a minute. An empty value turns that check off.
    |
    */

    'healthchecks' => [
        'scheduler' => env('HEALTHCHECKS_SCHEDULER_URL'),

        'tasks' => [
            'news:fetch' => env('HEALTHCHECKS_NEWS_FETCH_URL'),
            'debates:prune-recordings' => env('HEALTHCHECKS_PRUNE_RECORDINGS_URL'),
            'horizon:snapshot' => env('HEALTHCHECKS_HORIZON_SNAPSHOT_URL'),
            'queue:prune-failed' => env('HEALTHCHECKS_PRUNE_FAILED_URL'),
        ],
    ],

];
