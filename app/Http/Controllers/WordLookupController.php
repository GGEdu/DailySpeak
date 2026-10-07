<?php

namespace App\Http\Controllers;

use App\Rules\VocabularySelection;
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
            'text' => ['required', 'string', new VocabularySelection],
            'context' => ['nullable', 'string', 'max:2000'],
        ]);

        $text = WordLookup::normalise($data['text']);

        try {
            $explanation = $lookup->explain($text, $data['context'] ?? null, $request->user()->current_level);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => "Couldn't translate that right now. Try again in a moment."], 503);
        }

        return response()->json(['text' => $text] + $explanation);
    }
}
