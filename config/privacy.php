<?php

/*
|--------------------------------------------------------------------------
| Privacy and Retention
|--------------------------------------------------------------------------
|
| How long personal data is kept. Users' voice recordings keep their own
| setting (debate.audio.retention_days); debates:prune-recordings deletes
| them every night together with the settings below. Erasing an account
| (Settings) deletes all of its data at once.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Tutor Replies
    |--------------------------------------------------------------------------
    |
    | Days after which the tutor's synthesised replies are deleted. Empty means
    | the same as the users' recordings. Never less than one day.
    |
    */

    'replies_retention_days' => env('PRIVACY_REPLIES_RETENTION_DAYS'),

    /*
    |--------------------------------------------------------------------------
    | Transcripts and Reports
    |--------------------------------------------------------------------------
    |
    | Days after a debate ends before its transcripts, fluency report and audio
    | are deleted. 0 keeps them for as long as the account exists. Owner's
    | decision: no expiry by default.
    |
    */

    'transcripts_retention_days' => (int) env('PRIVACY_TRANSCRIPTS_RETENTION_DAYS', 0),

];
