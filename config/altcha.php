<?php

/*
|--------------------------------------------------------------------------
| ALTCHA Human Check
|--------------------------------------------------------------------------
|
| The check on sign-up and on "Forgot password": the browser solves a small
| proof-of-work puzzle in the background, with no images, cookies or third
| parties. The server signs every challenge with a key derived from APP_KEY
| and accepts each solution once, until the challenge expires.
|
| The difficulty is the work per attempt (cost) times the number of attempts
| the browser needs (256 for each byte of key_prefix_bytes). Raising either
| makes bots and phones slower alike; the defaults aim at one or two seconds
| on a phone.
|
*/

return [

    /*
    | PBKDF2-SHA-256 iterations for each attempt.
    */
    'cost' => max(1, (int) env('ALTCHA_COST', 20000)),

    /*
    | Bytes of the derived key the browser must match, from a random prefix
    | the server chooses for each challenge.
    */
    'key_prefix_bytes' => max(1, (int) env('ALTCHA_KEY_PREFIX_BYTES', 1)),

    /*
    | Seconds a challenge stays valid. A solution that is not used in time
    | has to be solved again.
    */
    'expires_in' => 600,

];
