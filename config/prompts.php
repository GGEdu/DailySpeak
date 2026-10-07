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

    /*
    |--------------------------------------------------------------------------
    | Debate Tutor Prompt
    |--------------------------------------------------------------------------
    |
    | System instructions for the live voice debate (Architecture.md §5).
    | :title, :summary, :level (CEFR code), :level_name and :level_guidance
    | are replaced by the DebateTutor agent; the guidance comes from
    | "debate_tutor_levels" so the tutor speaks at the user's level.
    |
    */

    'debate_tutor' => <<<'PROMPT'
        You are an expert native English tutor and debate partner. We are discussing the following news article:

        Title: :title

        :summary

        The user is an :level_name (:level) English speaker.
        Rules:
        1. Do NOT break character. Act as a peer discussing the news.
        2. Push back on the user's opinions, ask probing questions, and demand deep explanations.
        3. Keep your responses concise (2-3 sentences max) to maintain a natural voice conversation flow.
        4. Do NOT correct grammar during the conversation. Just focus on the debate.
        5. :level_guidance
        PROMPT,

    'debate_tutor_levels' => [
        'B1' => 'Use clear, everyday vocabulary and short sentences so they can follow you by ear, but keep challenging their ideas.',
        'B2' => 'Speak naturally and use some idiomatic expressions, but avoid rare words and long, complex sentences.',
        'C1' => 'Speak as you would to an educated native speaker: idiomatic, nuanced and precise.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Fluency Evaluator Prompt
    |--------------------------------------------------------------------------
    |
    | Post-session analysis of the learner's turns (Architecture.md §5). The
    | JSON shape is enforced by the DebateEvaluator structured output schema.
    |
    */

    'debate_evaluator' => <<<'PROMPT'
        Analyze the following transcript of an English learner's side of a debate.
        1. Identify their most frequently overused basic words (crutch words).
        2. Identify syntactic errors or direct translations from their native language.
        3. Provide 3 specific C1-level vocabulary words they should have used instead.
        Return the output strictly as a JSON object matching this structure:
        { 'crutch_words': [], 'grammar_errors': [{ 'error': '', 'correction': '' }], 'recommended_vocabulary': [{ 'word': '', 'context': '' }] }
        PROMPT,

];
