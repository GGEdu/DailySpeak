<?php

namespace App\Http\Controllers;

use App\Http\Resources\DebateMessageResource;
use App\Http\Resources\DebateResource;
use App\Http\Resources\NewsArticleResource;
use App\Models\Debate;
use App\Models\NewsArticle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Web (Inertia) side of a debate: starting it from the feed and the voice chat screen.
 */
class DebateSessionController extends Controller
{
    /**
     * Start (or resume) a debate about the article and open it.
     */
    public function store(Request $request, NewsArticle $newsArticle): RedirectResponse
    {
        $debate = $request->user()->startDebate($newsArticle);

        return to_route('debates.show', $debate);
    }

    /**
     * Show the voice debate screen.
     */
    public function show(Debate $debate): Response
    {
        $debate->load('newsArticle');

        return Inertia::render('Debate', [
            'debate' => [
                ...(new DebateResource($debate))->resolve(),
                'audio_upload_url' => route('debates.audio.store', $debate),
            ],
            'article' => (new NewsArticleResource($debate->newsArticle))->resolve(),
            'messages' => DebateMessageResource::collection($debate->messages()->orderBy('id')->get())->resolve(),
        ]);
    }
}
