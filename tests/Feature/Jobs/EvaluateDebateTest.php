<?php

namespace Tests\Feature\Jobs;

use App\Ai\Agents\DebateEvaluator;
use App\Events\DebateEvaluated;
use App\Events\DebateEvaluationFailed;
use App\Jobs\EvaluateDebate;
use App\Jobs\ProcessVoiceDebate;
use App\Models\Debate;
use App\Models\DebateMessage;
use App\Models\NewsArticle;
use App\Models\UserVocabulary;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\TestCase;

class EvaluateDebateTest extends TestCase
{
    use RefreshDatabase;

    private const REPORT = [
        'crutch_words' => ['very', 'thing', 'very'],
        'grammar_errors' => [
            ['error' => 'I am agree with this', 'correction' => 'I agree with this'],
        ],
        'recommended_vocabulary' => [
            ['word' => 'Contentious', 'context' => 'Free fares are a contentious policy.'],
            ['word' => 'ubiquitous', 'context' => 'Cars are ubiquitous in the city centre.'],
            ['word' => 'mitigate', 'context' => 'Free buses could mitigate congestion.'],
        ],
    ];

    private Debate $debate;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([DebateEvaluated::class, DebateEvaluationFailed::class]);

        $this->debate = Debate::factory()
            ->for(NewsArticle::factory()->state(['title' => 'City approves free buses']))
            ->create();
        $this->debate->finish();
        DebateMessage::factory()->for($this->debate)->fromUser()->create(['transcript' => 'I am agree with this, it is very good.']);
        DebateMessage::factory()->for($this->debate)->fromAssistant()->create(['transcript' => 'Why do you think so?']);
        DebateMessage::factory()->for($this->debate)->fromUser()->create(['transcript' => 'Because the thing is very expensive.']);
    }

    public function test_it_stores_the_report_and_adds_the_recommended_words(): void
    {
        DebateEvaluator::fake([self::REPORT])->preventStrayPrompts();

        EvaluateDebate::dispatch($this->debate);

        $this->assertSame([
            'crutch_words' => ['very', 'thing'],
            'grammar_errors' => [['error' => 'I am agree with this', 'correction' => 'I agree with this']],
            'recommended_vocabulary' => self::REPORT['recommended_vocabulary'],
        ], $this->debate->fresh()->ai_feedback);

        $words = $this->debate->user->vocabularies()->orderBy('id')->get();
        $this->assertSame(['contentious', 'ubiquitous', 'mitigate'], $words->pluck('word')->all());
        $this->assertSame('Free fares are a contentious policy.', $words[0]->context);
        $this->assertSame(UserVocabulary::MIN_MASTERY, $words[0]->mastery_level);
        $this->assertTrue($words[0]->next_review_at->lte(now()));

        Event::assertDispatched(DebateEvaluated::class, fn (DebateEvaluated $event) => $event->debate->is($this->debate)
            && $event->addedWords === ['contentious', 'ubiquitous', 'mitigate']);
    }

    public function test_the_evaluator_only_sees_the_learners_turns(): void
    {
        DebateEvaluator::fake([self::REPORT]);

        EvaluateDebate::dispatch($this->debate);

        DebateEvaluator::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->prompt, 'Debate topic: City approves free buses')
            && str_contains($prompt->prompt, "1. I am agree with this, it is very good.\n2. Because the thing is very expensive.")
            && ! str_contains($prompt->prompt, 'Why do you think so?')
            && str_contains((string) $prompt->agent->instructions(), "Analyze the following transcript of an English learner's side of a debate.")
            && $prompt->provider->name() === 'gemini');
    }

    public function test_words_already_in_the_deck_are_not_duplicated(): void
    {
        $existing = UserVocabulary::factory()->for($this->debate->user)->create(['word' => 'mitigate', 'mastery_level' => 4]);
        DebateEvaluator::fake([self::REPORT]);

        EvaluateDebate::dispatch($this->debate);

        $this->assertSame(3, $this->debate->user->vocabularies()->count());
        $this->assertSame(4, $existing->fresh()->mastery_level);
        Event::assertDispatched(DebateEvaluated::class, fn (DebateEvaluated $event) => $event->addedWords === ['contentious', 'ubiquitous']);
    }

    public function test_malformed_model_output_is_cleaned_up(): void
    {
        DebateEvaluator::fake([[
            'crutch_words' => ['  basically ', '', 42, 'Basically', 'the', 'Is', 'thing'],
            'grammar_errors' => [['error' => 'x'], 'nonsense', ['error' => 'He go', 'correction' => 'He goes']],
            'recommended_vocabulary' => [['word' => 'nuance'], ['word' => 'nuance', 'context' => 'A nuanced view.'], ['word' => 'NUANCE', 'context' => 'Again.']],
        ]]);

        EvaluateDebate::dispatch($this->debate);

        $this->assertSame([
            'crutch_words' => ['basically', 'thing'],
            'grammar_errors' => [['error' => 'He go', 'correction' => 'He goes']],
            'recommended_vocabulary' => [['word' => 'nuance', 'context' => 'A nuanced view.']],
        ], $this->debate->fresh()->ai_feedback);
    }

    public function test_a_debate_without_user_turns_gets_an_empty_report(): void
    {
        $silent = Debate::factory()->create();
        $silent->finish();
        DebateEvaluator::fake();

        EvaluateDebate::dispatch($silent);

        DebateEvaluator::assertNeverPrompted();
        $this->assertSame(EvaluateDebate::EMPTY_FEEDBACK, $silent->fresh()->ai_feedback);
        Event::assertDispatched(DebateEvaluated::class);
    }

    public function test_the_client_is_told_when_the_evaluation_fails(): void
    {
        DebateEvaluator::fake(fn () => throw new RuntimeException('Provider down'));

        try {
            EvaluateDebate::dispatch($this->debate);
        } catch (RuntimeException) {
            //
        }

        $this->assertNull($this->debate->fresh()->ai_feedback);
        $this->assertSame(0, UserVocabulary::count());
        Event::assertDispatched(DebateEvaluationFailed::class, fn ($event) => $event->debate->is($this->debate));
    }

    public function test_a_debate_is_queued_for_evaluation_only_once(): void
    {
        Queue::fake();

        EvaluateDebate::dispatch($this->debate);
        EvaluateDebate::dispatch($this->debate);

        Queue::assertPushed(EvaluateDebate::class, 1);
    }

    public function test_the_unique_lock_is_per_debate_and_outlives_the_retries(): void
    {
        $job = new EvaluateDebate($this->debate);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame((string) $this->debate->id, $job->uniqueId());
        $this->assertSame(600, $job->uniqueFor);
        // The lock lasts as long as the job may be retried.
        $this->assertEqualsWithDelta($job->uniqueFor, $job->retryUntil()->getTimestamp() - now()->getTimestamp(), 2);
    }

    public function test_a_report_that_already_exists_is_not_evaluated_again(): void
    {
        $this->debate->update(['ai_feedback' => self::REPORT]);
        DebateEvaluator::fake();

        EvaluateDebate::dispatch($this->debate);

        DebateEvaluator::assertNeverPrompted();
        $this->assertSame(self::REPORT, $this->debate->fresh()->ai_feedback);
        $this->assertSame(0, UserVocabulary::count());
        Event::assertNotDispatched(DebateEvaluated::class);
    }

    public function test_a_retry_reports_the_same_added_words(): void
    {
        // One answer per attempt: the retry asks the evaluator again.
        DebateEvaluator::fake([self::REPORT, self::REPORT]);
        // The first attempt fails when the report is saved, after the words were added to the deck.
        $failing = true;
        Debate::updating(function () use (&$failing) {
            if ($failing) {
                throw new RuntimeException('Database connection lost');
            }
        });

        try {
            EvaluateDebate::dispatch($this->debate);
            $this->fail('The first attempt should have failed.');
        } catch (RuntimeException) {
            //
        }
        $failing = false;

        // Nothing from the failed attempt is left behind, so the retry adds the same words.
        $this->assertSame(0, UserVocabulary::count());
        EvaluateDebate::dispatch($this->debate);

        $this->assertSame(['contentious', 'ubiquitous', 'mitigate'], $this->debate->user->vocabularies()->orderBy('id')->pluck('word')->all());
        Event::assertDispatched(DebateEvaluated::class, fn (DebateEvaluated $event) => $event->addedWords === ['contentious', 'ubiquitous', 'mitigate']);
    }

    public function test_a_failed_report_is_evaluated_when_it_is_requested_again(): void
    {
        DebateEvaluator::fake(fn () => throw new RuntimeException('Provider down'));

        try {
            EvaluateDebate::dispatch($this->debate);
        } catch (RuntimeException) {
            //
        }
        DebateEvaluator::fake([self::REPORT]);

        EvaluateDebate::dispatch($this->debate);

        $this->assertNotNull($this->debate->fresh()->ai_feedback);
        Event::assertDispatched(DebateEvaluated::class);
    }

    public function test_it_waits_for_voice_turns_still_being_answered(): void
    {
        $evaluation = collect((new EvaluateDebate($this->debate))->middleware())->first(fn ($m) => $m instanceof WithoutOverlapping);
        $voiceTurn = collect((new ProcessVoiceDebate($this->debate, 'turn.webm'))->middleware())->first(fn ($m) => $m instanceof WithoutOverlapping);

        $this->assertSame(
            $voiceTurn->getLockKey(new ProcessVoiceDebate($this->debate, 'turn.webm')),
            $evaluation->getLockKey(new EvaluateDebate($this->debate)),
        );
    }

    public function test_the_evaluator_uses_the_configured_timeout(): void
    {
        config(['debate.evaluator.timeout' => 75]);
        DebateEvaluator::fake([self::REPORT]);

        EvaluateDebate::dispatch($this->debate);

        DebateEvaluator::assertPrompted(fn (AgentPrompt $prompt) => $prompt->agent->timeout() === 75);
    }

    public function test_the_default_evaluator_timeout_fits_within_the_evaluation_job_budget(): void
    {
        $this->assertSame(60, config('debate.evaluator.timeout'));
        $this->assertLessThanOrEqual((new EvaluateDebate($this->debate))->timeout, config('debate.evaluator.timeout'));
    }

    public function test_the_report_is_broadcast_on_the_debate_channel(): void
    {
        $this->debate->update(['ai_feedback' => self::REPORT]);

        $event = new DebateEvaluated($this->debate, ['mitigate']);

        $this->assertEquals([new PrivateChannel("debates.{$this->debate->id}")], $event->broadcastOn());
        $this->assertSame([
            'debate_id' => $this->debate->id,
            'ai_feedback' => self::REPORT,
            'added_words' => ['mitigate'],
        ], $event->broadcastWith());
    }
}
