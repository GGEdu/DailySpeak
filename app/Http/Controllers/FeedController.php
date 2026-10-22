<?php

namespace App\Http\Controllers;

use App\Enums\DebateStatus;
use App\Http\Resources\NewsArticleResource;
use App\Models\NewsArticle;
use App\Models\NewsSource;
use App\Services\Limits;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

class FeedController extends Controller
{
    /**
     * How many more stories a search looks through than it shows, so hidden sources can be left out of it.
     */
    private const SEARCH_WINDOW = 3;

    /**
     * Number of most recent articles shown in the feed.
     */
    private const ARTICLES = 20;

    /**
     * Longest search the feed runs. Longer ones only add work for the engine and the database.
     */
    private const SEARCH_MAX_LENGTH = 100;

    /**
     * Show the latest harvested news, or the stories matching a search, ready to be debated.
     */
    public function __invoke(Request $request, Limits $limits): Response
    {
        $search = $this->searchTerm($request);
        // The sources this user hid are left out of the feed and of the search: they stay in the catalogue for everyone else.
        $hidden = $request->user()->hiddenSources()->pluck('news_source_id')->all();
        $categories = $this->categoriesWithArticles($hidden);
        // A search covers every category, so the category filter only applies to the latest news.
        $category = $search === '' ? $this->requestedCategory($request, $categories) : null;

        $articles = $search === ''
            ? $this->latest($category, $hidden)
            : $this->search($request, $search, $limits, $hidden);
        $articles->loadMissing('newsSource');

        $debates = $request->user()->debates()
            ->whereIn('news_article_id', $articles->modelKeys())
            ->orderBy('id')
            ->get(['id', 'news_article_id', 'status']);

        return Inertia::render('Feed', [
            'search' => $search,
            'category' => $category,
            'categories' => $categories,
            'articles' => NewsArticleResource::collection($articles)->resolve(),
            // news_article_id => debate_id, to offer "Continue debate" instead of starting a new one.
            'activeDebates' => $debates->where('status', DebateStatus::Active)->pluck('id', 'news_article_id'),
            // news_article_id => latest finished debate_id, to link to its fluency report.
            'finishedDebates' => $debates->where('status', DebateStatus::Completed)->pluck('id', 'news_article_id'),
        ]);
    }

    /**
     * The search typed in the box, trimmed and capped. An empty one means the latest news.
     */
    private function searchTerm(Request $request): string
    {
        $term = $request->query('q');

        return is_string($term) ? trim(mb_substr(trim($term), 0, self::SEARCH_MAX_LENGTH)) : '';
    }

    /**
     * The latest stories, or only those of one category.
     *
     * @return Collection<int, NewsArticle>
     */
    private function latest(?string $category, array $hidden): Collection
    {
        return NewsArticle::query()
            ->when($category, fn ($query) => $query->whereIn(
                'news_source_id',
                // The ids of the category's sources: cheaper than checking every article's source.
                NewsSource::query()->where('category', $category)->pluck('id'),
            ))
            ->when($hidden !== [], fn ($query) => $query->whereNotIn('news_source_id', $hidden))
            ->latest('published_at')
            ->latest('id')
            ->limit(self::ARTICLES)
            ->get();
    }

    /**
     * The categories that have at least one story, in the order of config/news.php.
     *
     * @return list<array{key: string, label: string}>
     */
    private function categoriesWithArticles(array $hidden): array
    {
        $filed = NewsSource::query()
            ->select('category')
            ->whereNotNull('category')
            ->whereNotIn('id', $hidden)
            ->whereHas('articles')
            ->distinct()
            ->pluck('category');

        return collect(config('news.categories'))
            ->filter(fn (string $label, string $key) => $filed->contains($key))
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
            ->values()
            ->all();
    }

    /**
     * The category asked for in the URL. An unknown one, or one without stories, is ignored (shown as "All").
     *
     * @param  list<array{key: string, label: string}>  $categories
     */
    private function requestedCategory(Request $request, array $categories): ?string
    {
        $requested = $request->query('category');

        return is_string($requested) && in_array($requested, array_column($categories, 'key'), true)
            ? $requested
            : null;
    }

    /**
     * Find the stories matching the search, newest first.
     *
     * @return Collection<int, NewsArticle>
     */
    private function search(Request $request, string $search, Limits $limits, array $hidden): Collection
    {
        // Each search can reach the engine and the database, so one user cannot run them without limit.
        if (! RateLimiter::attempt('feed-search:'.$request->user()->id, $limits->value('feed_searches_per_minute'), fn () => true)) {
            abort(429);
        }

        // The search as a LIKE pattern: its own % and _ are escaped, so "100%" finds "100%" and not every story.
        $pattern = '%'.addcslashes($search, '%_\\').'%';

        // A wider window than the page: the hidden sources are taken out of it afterwards, whichever engine answered.
        $found = rescue(
            fn () => NewsArticle::search($search)
                // Every word must match (allowing typos), not just the first ones.
                ->options(['matchingStrategy' => 'all'])
                ->orderBy('published_at', 'desc')
                ->take(self::ARTICLES * self::SEARCH_WINDOW)
                ->get(),
            // The feed keeps working, with a plain text search, if the search engine is down.
            fn () => NewsArticle::query()
                ->where(fn ($query) => $query
                    ->whereLike('title', $pattern)
                    ->orWhereLike('summary', $pattern))
                ->latest('published_at')
                ->latest('id')
                ->limit(self::ARTICLES * self::SEARCH_WINDOW)
                ->get(),
        );

        return $found
            ->reject(fn (NewsArticle $article) => in_array($article->news_source_id, $hidden, true))
            ->take(self::ARTICLES)
            ->values();
    }
}
