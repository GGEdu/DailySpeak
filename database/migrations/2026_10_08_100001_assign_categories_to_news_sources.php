<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The feeds that existed when categories were introduced, by feed URL.
     */
    private const CATEGORIES_BY_FEED = [
        'https://feeds.bbci.co.uk/news/world/rss.xml' => 'world',
        'https://www.aljazeera.com/xml/rss/all.xml' => 'world',
        'https://feeds.bbci.co.uk/news/business/rss.xml' => 'business',
        'https://feeds.bbci.co.uk/news/technology/rss.xml' => 'technology',
        'https://feeds.bbci.co.uk/news/science_and_environment/rss.xml' => 'science',
        'https://www.nasa.gov/news-release/feed/' => 'science',
        'https://feeds.bbci.co.uk/news/health/rss.xml' => 'health',
        'https://feeds.bbci.co.uk/news/education/rss.xml' => 'education',
        'https://www.theguardian.com/environment/rss' => 'environment',
        'https://www.theguardian.com/society/rss' => 'society',
        'https://www.theguardian.com/culture/rss' => 'culture',
        'https://www.smithsonianmag.com/rss/latest_articles/' => 'culture',
        'https://www.theguardian.com/sport/rss' => 'sport',
        'https://www.theguardian.com/lifeandstyle/rss' => 'lifestyle',
        'https://www.theguardian.com/travel/rss' => 'lifestyle',
        'https://aeon.co/feed.rss' => 'ideas',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::CATEGORIES_BY_FEED as $feedUrl => $category) {
            // A feed that is not in the table matches no row. A category already chosen by an admin is kept.
            DB::table('news_sources')
                ->where('feed_url', $feedUrl)
                ->whereNull('category')
                ->update(['category' => $category]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Nothing to undo here: the categories are removed with the column in its own migration.
    }
};
