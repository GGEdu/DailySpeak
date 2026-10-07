<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeVocabulary;
use App\Rules\VocabularySelection;
use App\Services\Vocabulary\WordLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Save a word or expression the user selected to "Your words", with the sentence it came from.
 */
class SaveWordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'word' => ['required', 'string', new VocabularySelection],
            'context' => ['nullable', 'string', 'max:2000'],
        ]);

        $context = filled($data['context'] ?? null) ? Str::limit(trim($data['context']), config('lookup.max_context_characters'), '') : null;

        $word = $request->user()->vocabularies()->firstOrCreate(
            ['word' => mb_strtolower(WordLookup::normalise($data['word']))],
            // Due straight away: the first review is the moment the user saved it.
            ['context' => $context, 'next_review_at' => now()],
        );

        if (! $word->wasRecentlyCreated && $word->context === null && $context !== null) {
            $word->update(['context' => $context]);
        }

        // A word the user also translated is answered from the lookup cache, at no extra cost.
        if ($word->analysis === null) {
            AnalyzeVocabulary::dispatch($word);
        }

        return response()->json(
            ['id' => $word->id, 'word' => $word->word, 'created' => $word->wasRecentlyCreated],
            $word->wasRecentlyCreated ? 201 : 200,
        );
    }
}
