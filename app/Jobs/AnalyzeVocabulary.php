<?php

namespace App\Jobs;

use App\Models\UserVocabulary;
use App\Services\Vocabulary\WordLookup;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Explain a saved word (translation, definition, example, synonyms) so it can be reviewed later.
 */
class AnalyzeVocabulary implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * A word removed before the job runs needs no analysis.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Release the uniqueness lock even if a worker is killed mid-job.
     */
    public int $uniqueFor = 300;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60];

    public function __construct(public UserVocabulary $word) {}

    /**
     * One analysis per word at a time, however many times it is saved.
     */
    public function uniqueId(): string
    {
        return (string) $this->word->id;
    }

    public function handle(WordLookup $lookup): void
    {
        if ($this->word->analysis !== null) {
            return;
        }

        self::store($this->word, $lookup);
    }

    /**
     * Look the word up in its saved sentence, at its owner's level, and keep the result.
     */
    public static function store(UserVocabulary $word, WordLookup $lookup): void
    {
        $explanation = $lookup->explain($word->word, $word->context, $word->user->current_level);

        $word->update([
            'translation' => $explanation['translation'],
            'analysis' => collect($explanation)->except('translation')->all(),
        ]);
    }
}
