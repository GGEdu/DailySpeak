<?php

namespace App\Http\Controllers;

use App\Models\UserVocabulary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Your words": spaced-repetition review of the vocabulary collected in fluency reports.
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

    public function destroy(UserVocabulary $vocabulary): RedirectResponse
    {
        $vocabulary->delete();

        return back();
    }
}
