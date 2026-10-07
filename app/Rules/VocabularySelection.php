<?php

namespace App\Rules;

use App\Services\Vocabulary\WordLookup;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A selection worth explaining or saving: a word or a short expression, not a sentence.
 */
class VocabularySelection implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $text = is_string($value) ? WordLookup::normalise($value) : '';

        if ($text === '' || ! preg_match('/\p{L}/u', $text)) {
            $fail('Select a word or a short expression.');
        } elseif (mb_strlen($text) > config('lookup.max_characters') || count(explode(' ', $text)) > config('lookup.max_words')) {
            $fail('Select a word or a short expression (up to '.config('lookup.max_words').' words), not a whole sentence.');
        }
    }
}
