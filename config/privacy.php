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

// A blank, zero or invalid number of days is null: "not set", so the replies keep the recordings' period. (int) would
// turn a blank into 0, and the replies would then be deleted a day after they were made.
$optionalDays = fn (string $key): ?int => filter_var(env($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

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

    'replies_retention_days' => $optionalDays('PRIVACY_REPLIES_RETENTION_DAYS'),

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

    /*
    |--------------------------------------------------------------------------
    | Who Is Responsible
    |--------------------------------------------------------------------------
    |
    | The data controller and the address for privacy requests, shown on the
    | privacy notice (GDPR art. 13). Each line is left out while it is empty:
    | these are the operator's facts, not something the app can make up.
    |
    */

    'controller' => env('PRIVACY_CONTROLLER') ?: null,

    'contact_email' => env('PRIVACY_CONTACT_EMAIL') ?: null,

];
