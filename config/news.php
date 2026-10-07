<?php

return [

    /*
    |--------------------------------------------------------------------------
    | RSS Feeds
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of RSS 2.0 feeds read by the news:fetch command.
    |
    */

    'feeds' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('NEWS_FEEDS', 'https://feeds.bbci.co.uk/news/world/rss.xml')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Harvesting Limits
    |--------------------------------------------------------------------------
    |
    | Each new article costs one LLM call, so only the most recent items of
    | every feed are processed. Articles whose page yields less text than
    | the minimum are skipped rather than summarised from the RSS teaser.
    |
    */

    'max_articles_per_feed' => (int) env('NEWS_MAX_ARTICLES_PER_FEED', 5),

    'min_article_characters' => 500,

    'max_article_characters' => 12000,

    /*
    |--------------------------------------------------------------------------
    | Summarisation Model
    |--------------------------------------------------------------------------
    |
    | Provider and model used by the NewsSummarizer agent. Providers are
    | defined by the Laravel AI SDK (e.g. "gemini" or "openai"). Leave the
    | model empty to use the provider's default text model.
    |
    */

    'ai' => [
        'provider' => env('NEWS_AI_PROVIDER', 'gemini'),
        'model' => env('NEWS_AI_MODEL') ?: null,
        'timeout' => (int) env('NEWS_AI_TIMEOUT', 60),
    ],

];
