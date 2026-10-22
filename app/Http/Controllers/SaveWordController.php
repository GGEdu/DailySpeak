<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeVocabulary;
use App\Rules\VocabularySelection;
use App\Services\Ai\AiCalls;
use App\Services\AiBudget;
use App\Services\AiBudgetUsedUp;
use App\Services\Vocabulary\WordLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Save a word or expression the user selected to "Your words", with the sentence it came from.
 */
class SaveWordController extends Controller
{
    public function __invoke(Request $request, AiCalls $calls): JsonResponse
    {
        $data = $request->validate([
            'word' => ['required', 'string', 'max:200', new VocabularySelection],
            'context' => ['nullable', 'string', 'max:2000'],
        ]);

        $context = filled($data['context'] ?? null) ? Str::limit(trim($data['context']), config('lookup.max_context_characters'), '') : null;
        $lemma = mb_strtolower(WordLookup::normalise($data['word']));

        // A word that is new, or not analysed yet, queues an analysis, which the owner's connection pays for when it
        // runs. Refused here, before anything is saved, when that connection or the cap or the budget does not allow it.
        $saved = $request->user()->vocabularies()->where('word', $lemma)->first();

        if ($saved === null || $saved->analysis === null) {
            try {
                $calls->check($request->user(), 'conversation');
            } catch (AiBudgetUsedUp) {
                return response()->json(['message' => AiBudget::USED_UP_MESSAGE], 429);
            }
        }

        // createOrFirst: two quick clicks on the same word race for the unique index; the loser gets the saved row.
        $word = $request->user()->vocabularies()->createOrFirst(
            ['word' => $lemma],
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
