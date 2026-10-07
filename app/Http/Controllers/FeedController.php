<?php

namespace App\Http\Controllers;

use App\Enums\DebateStatus;
use App\Http\Resources\NewsArticleResource;
use App\Models\NewsArticle;
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
     * Show the latest harvested news, ready to be debated.
     */
    public function __invoke(Request $request): Response
    {
        $articles = NewsArticle::query()
            ->latest('published_at')
            ->limit(self::ARTICLES)
            ->get();

        return Inertia::render('Feed', [
            'articles' => NewsArticleResource::collection($articles)->resolve(),
            // news_article_id => debate_id, to offer "Continue debate" instead of starting a new one.
            'activeDebates' => $request->user()->debates()
                ->where('status', DebateStatus::Active)
                ->whereIn('news_article_id', $articles->modelKeys())
                ->pluck('id', 'news_article_id'),
        ]);
    }
}
