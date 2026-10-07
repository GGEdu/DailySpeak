<?php

namespace App\Http\Controllers;

use App\Enums\DebateStatus;
use App\Http\Resources\NewsArticleResource;
use App\Models\NewsArticle;
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

        $articles = $search === ''
            ? NewsArticle::query()->latest('published_at')->limit(self::ARTICLES)->get()
            : $this->search($search);

        $debates = $request->user()->debates()
            ->whereIn('news_article_id', $articles->modelKeys())
            ->orderBy('id')
            ->get(['id', 'news_article_id', 'status']);

        return Inertia::render('Feed', [
            'search' => $search,
            'articles' => NewsArticleResource::collection($articles)->resolve(),
            // news_article_id => debate_id, to offer "Continue debate" instead of starting a new one.
            'activeDebates' => $debates->where('status', DebateStatus::Active)->pluck('id', 'news_article_id'),
            // news_article_id => latest finished debate_id, to link to its fluency report.
            'finishedDebates' => $debates->where('status', DebateStatus::Completed)->pluck('id', 'news_article_id'),
        ]);
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
