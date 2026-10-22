<?php

namespace Tests\Feature\Guided;

use App\Enums\DebateFlow;
use App\Enums\EnglishLevel;
use App\Models\Debate;
use App\Models\NewsArticle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuidedActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Starting a guided debate queues its brief. These tests are about the flow, not the brief.
        Queue::fake();
    }

    /**
     * @return array<string, array{EnglishLevel, DebateFlow}>
     */
    public static function defaults(): array
    {
        return [
            'B1 is guided by default' => [EnglishLevel::B1, DebateFlow::Guided],
            'B2 stays classic by default' => [EnglishLevel::B2, DebateFlow::Classic],
            'C1 stays classic by default' => [EnglishLevel::C1, DebateFlow::Classic],
        ];
    }

    #[DataProvider('defaults')]
    public function test_the_default_levels_decide_the_flow_of_a_new_debate(EnglishLevel $level, DebateFlow $flow): void
    {
        $this->assertSame($flow, $this->startAt($level)->flow);
    }

    public function test_an_admin_turns_a_level_on_and_the_next_debate_follows(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put('/admin/guided-debate/B2', ['enabled' => true])
            ->assertRedirect();

        $this->assertSame(DebateFlow::Guided, $this->startAt(EnglishLevel::B2)->flow);
        $this->assertSame(EnglishLevel::B2, $this->startAt(EnglishLevel::B2, 'another article')->level);
    }

    public function test_an_admin_turns_the_default_level_off(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put('/admin/guided-debate/B1', ['enabled' => false])
            ->assertRedirect();

        $this->assertSame(DebateFlow::Classic, $this->startAt(EnglishLevel::B1)->flow);
    }

    public function test_a_debate_keeps_the_flow_it_started_with_when_the_setting_changes(): void
    {
        $debate = $this->startAt(EnglishLevel::B1);

        $this->actingAs(User::factory()->admin()->create())
            ->put('/admin/guided-debate/B1', ['enabled' => false]);

        $this->assertSame(DebateFlow::Guided, $debate->fresh()->flow);
        $this->assertSame(DebateFlow::Classic, $this->startAt(EnglishLevel::B1, 'another article')->flow);
    }

    public function test_without_the_settings_table_the_defaults_apply(): void
    {
        Schema::drop('app_settings');

        $this->assertSame(DebateFlow::Guided, $this->startAt(EnglishLevel::B1)->flow);
        $this->assertSame(DebateFlow::Classic, $this->startAt(EnglishLevel::C1, 'another article')->flow);
    }

    public function test_only_admins_change_the_guided_setting(): void
    {
        $this->put('/admin/guided-debate/B2', ['enabled' => true])->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->put('/admin/guided-debate/B2', ['enabled' => true])->assertForbidden();

        $this->assertDatabaseEmpty('app_settings');
    }

    public function test_an_unknown_level_is_not_found_and_the_flag_is_required(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/guided-debate/B4', ['enabled' => true])->assertNotFound();
        $this->actingAs($admin)->put('/admin/guided-debate/B2', [])->assertSessionHasErrors('enabled');

        $this->assertDatabaseEmpty('app_settings');
    }

    public function test_the_limits_page_shows_one_switch_per_level(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->put('/admin/guided-debate/C1', ['enabled' => true]);

        $this->actingAs($admin)->get('/admin/limits')
            ->assertInertia(fn (Assert $page) => $page
                ->has('guidedDebate', 3)
                ->where('guidedDebate.0.level', 'B1')
                ->where('guidedDebate.0.enabled', true)
                ->where('guidedDebate.0.default', true)
                ->where('guidedDebate.0.customised', false)
                ->where('guidedDebate.1.level', 'B2')
                ->where('guidedDebate.1.enabled', false)
                ->where('guidedDebate.2.level', 'C1')
                ->where('guidedDebate.2.enabled', true)
                ->where('guidedDebate.2.customised', true));
    }

    /**
     * Starts a debate for a new learner at the level and returns it.
     */
    private function startAt(EnglishLevel $level, string $title = 'a debate article'): Debate
    {
        $user = User::factory()->level($level)->create();
        $article = NewsArticle::factory()->create(['title' => $title, 'source_url' => 'https://example.test/'.md5($title.$level->value)]);

        $this->actingAs($user)->post(route('debates.start', $article))->assertRedirect();

        return $user->debates()->latest('id')->firstOrFail();
    }
}
