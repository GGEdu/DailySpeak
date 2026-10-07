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

        $debates = $request->user()->debates()
            ->whereIn('news_article_id', $articles->modelKeys())
            ->orderBy('id')
            ->get(['id', 'news_article_id', 'status']);

        return Inertia::render('Feed', [
            'articles' => NewsArticleResource::collection($articles)->resolve(),
            // news_article_id => debate_id, to offer "Continue debate" instead of starting a new one.
            'activeDebates' => $debates->where('status', DebateStatus::Active)->pluck('id', 'news_article_id'),
            // news_article_id => latest finished debate_id, to link to its fluency report.
            'finishedDebates' => $debates->where('status', DebateStatus::Completed)->pluck('id', 'news_article_id'),
        ]);
    }
}
