<?php

namespace App\Http\Controllers;

use App\Http\Resources\DebateMessageResource;
use App\Http\Resources\DebateResource;
use App\Http\Resources\NewsArticleResource;
use App\Jobs\EvaluateDebate;
use App\Models\Debate;
use App\Models\NewsArticle;
use App\Services\AiBudget;
use App\Services\Guided\DebateStarter;
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
    public function store(Request $request, NewsArticle $newsArticle, DebateStarter $starter): RedirectResponse
    {
        $debate = $starter->start($request->user(), $newsArticle);

        return to_route('debates.show', $debate);
    }

    /**
     * Show the voice debate screen.
     */
    public function show(Debate $debate): Response
    {
        // Closures, so that the report poll (a partial reload of `debate` only) does not rebuild them.
        return Inertia::render('Debate', [
            'debate' => [
                ...(new DebateResource($debate))->resolve(),
                'audio_upload_url' => route('debates.audio.store', $debate),
            ],
            'article' => fn () => (new NewsArticleResource($debate->newsArticle))->resolve(),
            'messages' => fn () => DebateMessageResource::collection($debate->messages()->orderBy('id')->get())->resolve(),
        ]);
    }

    /**
     * Finish the debate and queue its fluency report (or retry a report that failed).
     */
    public function finish(Request $request, Debate $debate, AiBudget $budget): RedirectResponse
    {
        // The report is the paid call. It is taken from the budget before the debate is closed, so a refusal
        // leaves the debate open for another try.
        if (! $budget->spend($request->user())) {
            return back()->withErrors(['finish' => AiBudget::USED_UP_MESSAGE]);
        }

        $debate->finish();

        EvaluateDebate::dispatch($debate);

        return to_route('debates.show', $debate);
    }
}
