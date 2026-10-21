<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeVocabulary;
use App\Models\UserVocabulary;
use App\Services\Vocabulary\WordLookup;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * "Your words": spaced-repetition review of the words the user saved and those recommended in fluency reports.
 */
class VocabularyController extends Controller
{
    /**
     * Words in one review session. The rest follow as the user works through them.
     */
    private const DUE_LIMIT = 50;

    /**
     * Words per page of the full list.
     */
    private const LIST_PER_PAGE = 50;

    public function index(Request $request): Response
    {
        return Inertia::render('Vocabulary', [
            'stats' => $this->stats($request),
            // The card shows the meaning, so each due word carries its analysis.
            'due' => $this->vocabularies($request)
                ->due()
                ->orderBy('next_review_at')
                ->limit(self::DUE_LIMIT)
                ->get()
                ->map(fn (UserVocabulary $word) => [
                    'id' => $word->id,
                    'word' => $word->word,
                    'context' => $word->context,
                    'translation' => $word->translation,
                    'analysis' => $word->analysis,
                    'mastery_level' => $word->mastery_level,
                    'next_review_at' => $word->next_review_at,
                ])
                ->values(),
            // Loaded after the page renders, one page at a time, without the analyses.
            'words' => Inertia::defer(fn () => $this->words($request)),
            // The meaning of one word in the list, requested when the user opens it.
            'explanation' => Inertia::optional(fn () => $this->explanation($request)),
            'maxLevel' => UserVocabulary::MAX_MASTERY,
        ]);
    }

    /**
     * The user's own words, as a fresh query.
     */
    private function vocabularies(Request $request): HasMany
    {
        return $request->user()->vocabularies();
    }

    /**
     * The counters shown above the cards: small, so they come with the page.
     *
     * @return array{total: int, due: int, mastered: int, next_review_at: ?Carbon}
     */
    private function stats(Request $request): array
    {
        $next = $this->vocabularies($request)
            ->where('next_review_at', '>', now())
            ->orderBy('next_review_at')
            ->first(['next_review_at']);

        return [
            'total' => $this->vocabularies($request)->count(),
            'due' => $this->vocabularies($request)->due()->count(),
            'mastered' => $this->vocabularies($request)->where('mastery_level', UserVocabulary::MAX_MASTERY)->count(),
            'next_review_at' => $next?->next_review_at,
        ];
    }

    /**
     * One page of the alphabetical list. The analysis is left out: it is asked for when a word is opened.
     *
     * @return array{data: list<array<string, mixed>>, current_page: int, last_page: int}
     */
    private function words(Request $request): array
    {
        $page = $this->vocabularies($request)
            ->select(['id', 'word', 'context', 'translation', 'mastery_level', 'next_review_at'])
            // Whether the word can be opened, without loading the analysis to find out.
            ->selectRaw('(analysis IS NOT NULL)::int AS has_analysis')
            ->orderBy('word')
            ->orderBy('id')
            ->paginate(self::LIST_PER_PAGE);

        return [
            'data' => collect($page->items())
                ->map(fn (UserVocabulary $word) => [
                    'id' => $word->id,
                    'word' => $word->word,
                    'context' => $word->context,
                    'translation' => $word->translation,
                    'has_analysis' => (bool) $word->has_analysis,
                    'mastery_level' => $word->mastery_level,
                    'next_review_at' => $word->next_review_at,
                ])
                ->values()
                ->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
        ];
    }

    /**
     * The analysis of one word, for the user who opened it in the list.
     *
     * @return array{id: ?int, analysis: ?array<string, mixed>}
     */
    private function explanation(Request $request): array
    {
        $word = $this->vocabularies($request)->find($request->integer('explain'));

        return ['id' => $word?->id, 'analysis' => $word?->analysis];
    }

    /**
     * Record whether the user remembered the word.
     */
    public function review(Request $request, UserVocabulary $vocabulary): RedirectResponse
    {
        $vocabulary->review($request->validate(['remembered' => ['required', 'boolean']])['remembered']);

        return back();
    }

    /**
     * Explain a word that has no analysis yet (e.g. one recommended in a fluency report).
     */
    public function analyze(UserVocabulary $vocabulary, WordLookup $lookup): RedirectResponse
    {
        if ($vocabulary->analysis !== null) {
            return back();
        }

        try {
            AnalyzeVocabulary::store($vocabulary, $lookup);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['analysis' => "Couldn't explain “{$vocabulary->word}” right now. Try again in a moment."]);
        }

        return back();
    }

    public function destroy(UserVocabulary $vocabulary): RedirectResponse
    {
        $vocabulary->delete();

        return back();
    }
}
