<?php

namespace App\Http\Controllers;

use App\Enums\DebateStatus;
use App\Http\Resources\NewsArticleResource;
use App\Models\NewsArticle;
use App\Models\NewsSource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FeedController extends Controller
{
    /**
     * Number of most recent articles shown in the feed.
     */
    private const ARTICLES = 20;

    /**
     * Show the latest harvested news, or the stories matching a search, ready to be debated.
     */
    public function __invoke(Request $request): Response
    {
        $search = trim((string) $request->string('q'));
        $categories = $this->categoriesWithArticles();
        // A search covers every category, so the category filter only applies to the latest news.
        $category = $search === '' ? $this->requestedCategory($request, $categories) : null;

        $articles = $search === ''
            ? $this->latest($category)
            : $this->search($search);
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
     * The latest stories, or only those of one category.
     *
     * @return Collection<int, NewsArticle>
     */
    private function latest(?string $category): Collection
    {
        return NewsArticle::query()
            ->when($category, fn ($query) => $query->whereHas(
                'newsSource',
                fn ($source) => $source->where('category', $category),
            ))
            ->latest('published_at')
            ->limit(self::ARTICLES)
            ->get();
    }

    /**
     * The categories that have at least one story, in the order of config/news.php.
     *
     * @return list<array{key: string, label: string}>
     */
    private function categoriesWithArticles(): array
    {
        $filed = NewsSource::query()
            ->select('category')
            ->whereNotNull('category')
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
    private function search(string $search): Collection
    {
        return rescue(
            fn () => NewsArticle::search($search)
                // Every word must match (allowing typos), not just the first ones.
                ->options(['matchingStrategy' => 'all'])
                ->orderBy('published_at', 'desc')
                ->take(self::ARTICLES)
                ->get(),
            // The feed keeps working, with a plain text search, if the search engine is down.
            fn () => NewsArticle::query()
                ->where(fn ($query) => $query
                    ->whereLike('title', "%{$search}%")
                    ->orWhereLike('summary', "%{$search}%"))
                ->latest('published_at')
                ->limit(self::ARTICLES)
                ->get(),
        );
    }
}
