<?php

namespace App\Http\Controllers;

use App\Http\Resources\DebateMessageResource;
use App\Http\Resources\DebateResource;
use App\Http\Resources\NewsArticleResource;
use App\Jobs\EvaluateDebate;
use App\Models\Debate;
use App\Models\NewsArticle;
use App\Services\Ai\AiCalls;
use App\Services\AiBudget;
use App\Services\AiBudgetUsedUp;
use App\Services\Guided\DebateBriefs;
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
    public function show(Debate $debate, DebateBriefs $briefs): Response
    {
        // Closures, so that the report poll (a partial reload of `debate` only) does not rebuild them. The guided
        // props are closures too: a partial reload evaluates only the ones it names (the client polls the brief).
        return Inertia::render('Debate', [
            'debate' => [
                ...(new DebateResource($debate))->resolve(),
                'audio_upload_url' => route('debates.audio.store', $debate),
            ],
            'article' => fn () => (new NewsArticleResource($debate->newsArticle))->resolve(),
            'messages' => fn () => DebateMessageResource::collection($debate->messages()->orderBy('id')->get())->resolve(),
            'flow' => fn () => $debate->flow->value,
            'brief' => fn () => $briefs->brief($debate),
            'briefStatus' => fn () => $briefs->status($debate),
            'briefFailureReason' => fn () => $briefs->failureReason($debate),
        ]);
    }

    /**
     * Finish the debate and queue its fluency report (or retry a report that failed).
     */
    public function finish(Request $request, Debate $debate, AiCalls $calls): RedirectResponse
    {
        // The report is the paid call. Its connection, the cap and the platform budget are checked before the debate is
        // closed, so a refusal leaves the debate open for another try (a missing connection is answered under "ai").
        try {
            $calls->check($request->user(), 'writing');
        } catch (AiBudgetUsedUp) {
            return back()->withErrors(['finish' => AiBudget::USED_UP_MESSAGE]);
        }

        $debate->finish();

        EvaluateDebate::dispatch($debate);

        return to_route('debates.show', $debate);
    }
}
