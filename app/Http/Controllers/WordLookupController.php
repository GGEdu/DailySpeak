<?php

namespace App\Http\Controllers;

use App\Rules\VocabularySelection;
use App\Services\Ai\AiCapReached;
use App\Services\Ai\AiNotConfigured;
use App\Services\AiBudget;
use App\Services\AiBudgetUsedUp;
use App\Services\Vocabulary\WordLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Translate and explain the word or expression the user selected in a text.
 */
class WordLookupController extends Controller
{
    public function __invoke(Request $request, WordLookup $lookup): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:200', new VocabularySelection],
            'context' => ['nullable', 'string', 'max:2000'],
        ]);

        $text = WordLookup::normalise($data['text']);

        // The asker's own connection pays, and only when the AI is asked: a cached answer costs nothing, and a refused
        // request costs nothing either (AiNotConfigured answers 409 and AiCapReached 429, see bootstrap/app.php).
        try {
            $explanation = $lookup->explain(
                $text,
                $data['context'] ?? null,
                $request->user()->current_level,
                $request->user(),
            );
        } catch (AiBudgetUsedUp) {
            return response()->json(['message' => AiBudget::USED_UP_MESSAGE], 429);
        } catch (AiNotConfigured|AiCapReached $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => "Couldn't translate that right now. Try again in a moment."], 503);
        }

        return response()->json(['text' => $text] + $explanation);
    }
}
