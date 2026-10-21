<?php

return [

    /*
    | The RSS feeds themselves are stored in the news_sources table and are
    | managed by admins at /admin/sources.
    */

    /*
    |--------------------------------------------------------------------------
    | Categories
    |--------------------------------------------------------------------------
    |
    | The closed list of categories a source can be filed under. The key is
    | stored in news_sources.category; the label is shown in the feed.
    |
    */

    'categories' => [
        'world' => 'World',
        'business' => 'Business',
        'technology' => 'Technology',
        'science' => 'Science',
        'health' => 'Health',
        'education' => 'Education',
        'environment' => 'Environment',
        'society' => 'Society',
        'culture' => 'Culture',
        'sport' => 'Sport',
        'lifestyle' => 'Lifestyle',
        'ideas' => 'Ideas',
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily Schedule
    |--------------------------------------------------------------------------
    |
    | When the scheduler runs news:fetch, in the scheduler's timezone
    | (app.schedule_timezone, Europe/Madrid by default).
    |
    */

    'schedule' => [
        'time' => env('NEWS_FETCH_TIME', '03:00'),
    ],

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

    'max_articles_per_feed' => max(1, (int) env('NEWS_MAX_ARTICLES_PER_FEED', 5)),

    'min_article_characters' => 500,

    'max_article_characters' => 12000,

    // A summary longer than this is refused: the model went beyond what the page says.
    'max_summary_characters' => 2000,

    // Items whose page has no usable text (videos, live pages) are not downloaded again for this many days.
    'skipped_items_days' => 7,

    // Items whose summary failed (model error or invalid output) are not sent to the model again for this many hours.
    'failed_summary_hours' => 24,

    // A source stops after this many articles in a row failed, so a broken feed or a down model costs little.
    'max_consecutive_failures' => 3,

    // The whole daily run stops starting new articles after this many seconds, however many feeds are left.
    'run_time_budget_seconds' => 3600,

    /*
    |--------------------------------------------------------------------------
    | Downloads
    |--------------------------------------------------------------------------
    |
    | Feeds and article pages come from arbitrary hosts. Responses are capped
    | in size, and any host that resolves to a private, loopback, link-local
    | or otherwise reserved address is refused, redirects included. Enable
    | NEWS_ALLOW_PRIVATE_HOSTS only on a development machine.
    |
    */

    'max_feed_bytes' => 5 * 1024 * 1024,

    'max_page_bytes' => 3 * 1024 * 1024,

    // Parsed as a boolean: (bool) would read "no" and "off" as true and turn the protection off.
    'allow_private_hosts' => filter_var(env('NEWS_ALLOW_PRIVATE_HOSTS', false), FILTER_VALIDATE_BOOL),

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
        'timeout' => max(1, (int) env('NEWS_AI_TIMEOUT', 60)),
    ],

];
