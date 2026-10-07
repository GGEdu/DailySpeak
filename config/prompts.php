<?php

return [

    /*
    |--------------------------------------------------------------------------
    | News Summary Prompt
    |--------------------------------------------------------------------------
    |
    | Instructions given to the NewsSummarizer agent for every harvested
    | article. The JSON shape is also enforced through the agent's
    | structured output schema, so both must be kept in sync.
    |
    */

    'news_summary' => <<<'PROMPT'
        Resume esta noticia en 3 párrafos para un estudiante de inglés C1. Extrae 5 términos de vocabulario avanzado. Devuelve estrictamente JSON: {summary: '', vocabulary: ['word1', 'word2']}
        Escribe el resumen y el vocabulario en inglés, separando los párrafos con una línea en blanco.
        PROMPT,

];
