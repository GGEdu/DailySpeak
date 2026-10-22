<?php

namespace Tests\Feature\Ai;

use App\Jobs\ReviewLearnerTurn;
use App\Models\AiUsage;
use App\Models\Debate;
use App\Models\DebateMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\ConfiguresUserAi;
use Tests\TestCase;

/**
 * Jobs resolve the connection of the user they serve, where they run: two jobs in a row, for two users, each pay with
 * their own key, and no key ever reaches a job's payload.
 */
class JobConnectionsTest extends TestCase
{
    use ConfiguresUserAi;
    use RefreshDatabase;

    private const ALICE_KEY = 'gsk-alice-1111';

    private const BRUNO_KEY = 'gsk-bruno-2222';

    private function turnOf(User $user): DebateMessage
    {
        $debate = Debate::factory()->for($user)->create();

        return DebateMessage::factory()->for($debate)->fromUser()->create();
    }

    private function fakeGroq(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'id' => 'chatcmpl-1',
            'model' => 'llama-3.3-70b-versatile',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => json_encode(['corrections' => []])], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3, 'total_tokens' => 10],
        ])]);
    }

    public function test_two_jobs_for_two_users_each_pay_with_their_own_key(): void
    {
        $alice = $this->userWithAi('groq', self::ALICE_KEY, tasks: ['conversation']);
        $bruno = $this->userWithAi('groq', self::BRUNO_KEY, tasks: ['conversation']);
        $aliceTurn = $this->turnOf($alice);
        $brunoTurn = $this->turnOf($bruno);
        $this->fakeGroq();

        ReviewLearnerTurn::dispatch($aliceTurn);
        ReviewLearnerTurn::dispatch($brunoTurn);

        $bearers = collect(Http::recorded())
            ->map(fn (array $pair) => $pair[0]->header('Authorization')[0] ?? null)
            ->values()
            ->all();

        $this->assertSame(['Bearer '.self::ALICE_KEY, 'Bearer '.self::BRUNO_KEY], $bearers);
        $this->assertSame([$alice->id, $bruno->id], AiUsage::query()->orderBy('id')->pluck('user_id')->all());
    }

    public function test_a_job_payload_never_carries_the_key(): void
    {
        $alice = $this->userWithAi('groq', self::ALICE_KEY, tasks: ['conversation']);

        $payload = serialize(new ReviewLearnerTurn($this->turnOf($alice)));

        $this->assertStringNotContainsString(self::ALICE_KEY, $payload);
        $this->assertStringNotContainsString('gsk-', $payload);
    }

    public function test_a_user_without_a_key_who_may_use_the_platform_is_paid_as_the_platform(): void
    {
        config(['debate.llm.provider' => 'groq', 'debate.llm.model' => 'llama-3.3-70b-versatile']);
        $user = $this->allowPlatformAi($this->userWithAi('groq', self::ALICE_KEY, tasks: ['conversation']));
        $user->aiKeys()->delete();
        $turn = $this->turnOf($user);
        $this->fakeGroq();

        ReviewLearnerTurn::dispatch($turn);

        $row = AiUsage::query()->sole();
        $this->assertSame('platform', $row->paid_by);
        $this->assertSame($user->id, $row->user_id);
        $this->assertTrue($row->ok);
    }

    public function test_a_user_without_a_key_and_without_the_platform_reviews_nothing_and_is_not_charged(): void
    {
        $user = $this->userWithAi('groq', self::ALICE_KEY, tasks: ['conversation']);
        $user->aiKeys()->delete();
        $turn = $this->turnOf($user);
        $this->fakeGroq();

        ReviewLearnerTurn::dispatch($turn);

        Http::assertNothingSent();
        $this->assertSame([], $turn->fresh()->corrections);
        $this->assertSame(0, AiUsage::query()->count());
    }
}
