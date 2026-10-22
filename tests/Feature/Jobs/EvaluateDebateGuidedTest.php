<?php

namespace Tests\Feature\Jobs;

use App\Ai\Agents\DebateEvaluator;
use App\Enums\EnglishLevel;
use App\Events\DebateEvaluated;
use App\Jobs\EvaluateDebate;
use App\Models\Debate;
use App\Models\DebateMessage;
use App\Models\NewsArticle;
use App\Models\User;
use App\Services\Guided\LearnerCorrections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class EvaluateDebateGuidedTest extends TestCase
{
    use RefreshDatabase;

    private Debate $debate;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([DebateEvaluated::class]);

        $this->debate = Debate::factory()
            ->guided(EnglishLevel::B1)
            ->for(User::factory())
            ->for(NewsArticle::factory())
            ->create();
        $this->debate->finish();

        $first = DebateMessage::factory()->for($this->debate)->fromUser()->create(['transcript' => 'I am agree with this. The buses is expensive.']);
        DebateMessage::factory()->for($this->debate)->fromAssistant()->create(['transcript' => 'Why?']);
        $second = DebateMessage::factory()->for($this->debate)->fromUser()->create(['transcript' => 'It is very good for people.']);

        $first->forceFill(['corrections' => [
            ['original' => 'I am agree with this', 'better' => 'I agree with this', 'note' => 'Use agree.', 'dismissed' => false],
            ['original' => 'The buses is expensive', 'better' => 'The buses are expensive', 'note' => null, 'dismissed' => true],
        ]])->save();
        $second->forceFill(['corrections' => [
            ['original' => 'It is very good', 'better' => 'It is really good', 'note' => null, 'dismissed' => false],
        ]])->save();
    }

    /**
     * @param  list<array{error: string, correction: string}>  $grammarErrors
     */
    private function evaluatorFinds(array $grammarErrors): void
    {
        DebateEvaluator::fake([[
            'crutch_words' => ['very'],
            'grammar_errors' => $grammarErrors,
            'recommended_vocabulary' => [
                ['word' => 'contentious', 'context' => 'Fares are a contentious policy.'],
                ['word' => 'ubiquitous', 'context' => 'Cars are ubiquitous in the city.'],
                ['word' => 'mitigate', 'context' => 'Free buses could mitigate congestion.'],
            ],
        ]])->preventStrayPrompts();
    }

    public function test_the_report_includes_the_turns_corrections_that_were_not_dismissed(): void
    {
        $this->evaluatorFinds([]);

        EvaluateDebate::dispatch($this->debate);

        $this->assertSame([
            ['error' => 'I am agree with this', 'correction' => 'I agree with this'],
            ['error' => 'It is very good', 'correction' => 'It is really good'],
        ], $this->debate->fresh()->ai_feedback['grammar_errors']);
    }

    public function test_the_evaluator_errors_come_first_and_are_not_repeated(): void
    {
        $this->evaluatorFinds([
            ['error' => 'i am AGREE with this', 'correction' => 'I agree with this'],
            ['error' => 'The house are big', 'correction' => 'The house is big'],
        ]);

        EvaluateDebate::dispatch($this->debate);

        $this->assertSame([
            ['error' => 'i am AGREE with this', 'correction' => 'I agree with this'],
            ['error' => 'The house are big', 'correction' => 'The house is big'],
            ['error' => 'It is very good', 'correction' => 'It is really good'],
        ], $this->debate->fresh()->ai_feedback['grammar_errors']);
    }

    public function test_a_dismissed_correction_stays_out_of_the_report(): void
    {
        $this->evaluatorFinds([]);
        EvaluateDebate::dispatch($this->debate);

        $errors = array_column($this->debate->fresh()->ai_feedback['grammar_errors'], 'error');
        $this->assertNotContains('The buses is expensive', $errors);
    }

    public function test_the_report_keeps_its_shape(): void
    {
        $this->evaluatorFinds([]);

        EvaluateDebate::dispatch($this->debate);

        $feedback = $this->debate->fresh()->ai_feedback;
        $this->assertSame(['crutch_words', 'grammar_errors', 'recommended_vocabulary'], array_keys($feedback));
        foreach ($feedback['grammar_errors'] as $error) {
            $this->assertSame(['error', 'correction'], array_keys($error));
        }
    }

    public function test_a_debate_without_corrections_reports_what_the_evaluator_found(): void
    {
        DebateMessage::query()->update(['corrections' => null]);
        $this->evaluatorFinds([['error' => 'I am agree', 'correction' => 'I agree']]);

        EvaluateDebate::dispatch($this->debate);

        $this->assertSame([['error' => 'I am agree', 'correction' => 'I agree']], $this->debate->fresh()->ai_feedback['grammar_errors']);
    }

    public function test_the_turn_corrections_are_merged_by_the_learner_corrections_service(): void
    {
        $this->assertSame(
            ['I am agree with this', 'It is very good'],
            array_column(app(LearnerCorrections::class)->mergeIntoReport($this->debate, []), 'error'),
        );
    }
}
