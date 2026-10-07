<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeVocabulary;
use App\Models\UserVocabulary;
use App\Services\Vocabulary\WordLookup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * "Your words": spaced-repetition review of the words the user saved and those recommended in fluency reports.
 */
class VocabularyController extends Controller
{
    public function index(Request $request): Response
    {
        $words = $request->user()->vocabularies()->orderBy('next_review_at')->get();

        $present = fn (UserVocabulary $word) => [
            'id' => $word->id,
            'word' => $word->word,
            'context' => $word->context,
            'translation' => $word->translation,
            'analysis' => $word->analysis,
            'mastery_level' => $word->mastery_level,
            'next_review_at' => $word->next_review_at,
        ];

        return Inertia::render('Vocabulary', [
            'due' => $words->filter(fn (UserVocabulary $word) => $word->next_review_at->lte(now()))->values()->map($present),
            'words' => $words->sortBy('word')->values()->map($present),
            'maxLevel' => UserVocabulary::MAX_MASTERY,
        ]);
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
