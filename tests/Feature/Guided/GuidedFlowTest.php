<?php

namespace Tests\Feature\Guided;

use App\Enums\DebateFlow;
use App\Enums\EnglishLevel;
use App\Models\Debate;
use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuidedFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Starting a guided debate queues its brief. These tests are about the flow, not the brief.
        Queue::fake();
    }

    /**
     * @return array<string, array{EnglishLevel}>
     */
    public static function levels(): array
    {
        return [
            'B1' => [EnglishLevel::B1],
            'B2' => [EnglishLevel::B2],
            'C1' => [EnglishLevel::C1],
        ];
    }

    #[DataProvider('levels')]
    public function test_every_new_debate_is_guided_at_every_level(EnglishLevel $level): void
    {
        $debate = $this->startAt($level);

        $this->assertSame(DebateFlow::Guided, $debate->flow);
        $this->assertSame($level, $debate->level);
    }

    public function test_a_debate_started_before_the_guided_flow_keeps_its_classic_flow(): void
    {
        $classic = Debate::factory()->for(User::factory())->for(NewsArticle::factory())->create();

        $this->assertSame(DebateFlow::Classic, $classic->fresh()->flow);
    }

    public function test_the_switch_per_level_is_gone(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put('/admin/guided-debate/B2', ['enabled' => false])
            ->assertNotFound();
    }

    public function test_the_limits_page_has_no_guided_section(): void
    {
        $this->actingAs(User::factory()->admin()->create())->get('/admin/limits')
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Limits')
                ->missing('guidedDebate'));
    }

    /**
     * Starts a debate for a new learner at the level and returns it.
     */
    private function startAt(EnglishLevel $level): Debate
    {
        $user = User::factory()->level($level)->create();
        $article = NewsArticle::factory()->create(['source_url' => 'https://example.test/'.md5($level->value)]);

        $this->actingAs($user)->post(route('debates.start', $article))->assertRedirect();

        return $user->debates()->latest('id')->firstOrFail();
    }
}
