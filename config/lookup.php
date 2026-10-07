<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Word Lookup Model
    |--------------------------------------------------------------------------
    |
    | Explains the word or expression the user selects: translation, a short
    | definition, an example and synonyms. The user is waiting for it, so
    | pick a fast model that does not reason (e.g. a "chat" gateway alias).
    |
    */

    'ai' => [
        'provider' => env('LOOKUP_AI_PROVIDER', 'gemini'),
        'model' => env('LOOKUP_AI_MODEL') ?: null,
        'timeout' => (int) env('LOOKUP_AI_TIMEOUT', 20),
    ],

    // The learners' own language, which selections are translated into.
    'target_language' => env('LOOKUP_TARGET_LANGUAGE', 'Spanish'),

    /*
    |--------------------------------------------------------------------------
    | What Counts as Vocabulary
    |--------------------------------------------------------------------------
    |
    | Longer selections are sentences, not vocabulary. Only the sentence the
    | selection appears in is sent as context, cut to a reasonable length.
    |
    */

    'max_characters' => 60,
    'max_words' => 5,
    'max_context_characters' => 500,

    // The same selection in the same sentence gets the same answer, so it is cached.
    'cache_days' => 30,

];
