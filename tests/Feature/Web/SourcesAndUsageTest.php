<?php

namespace Tests\Feature\Web;

use App\Models\AiUsage;
use App\Models\NewsArticle;
use App\Models\NewsSource;
use App\Models\User;
use App\Models\UserHiddenSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Settings → Sources (what the user hides from their own feed) and Settings → Usage (this month's calls and the cap).
 */
class SourcesAndUsageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The pages these tests render (settings/Ai, settings/Sources, settings/Usage, admin/Users) are built by the client
     * side; the props they receive are what is checked here.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['inertia.testing.ensure_pages_exist' => false]);
    }

    public function test_the_sources_page_lists_the_active_sources_with_what_the_user_hid(): void
    {
        // The catalogue already holds the sources the migrations seed: the test looks at its own by name.
        $user = User::factory()->create();
        NewsSource::factory()->create(['name' => 'Alpha Test News']);
        $hidden = NewsSource::factory()->create(['name' => 'Bravo Test News']);
        NewsSource::factory()->inactive()->create(['name' => 'Paused Test News']);
        UserHiddenSource::query()->create(['user_id' => $user->id, 'news_source_id' => $hidden->id]);

        $page = $this->actingAs($user)->get('/settings/sources')->assertOk()->viewData('page');
        $sources = collect($page['props']['sources'])->keyBy('name');

        $this->assertSame('settings/Sources', $page['component']);
        $this->assertFalse($sources['Alpha Test News']['hidden']);
        $this->assertTrue($sources['Bravo Test News']['hidden']);
        $this->assertArrayHasKey('policy_status', $sources['Alpha Test News']);
        $this->assertFalse($sources->has('Paused Test News'), 'A paused source is not offered.');
    }

    public function test_a_source_hidden_by_the_user_is_left_out_of_their_feed_only(): void
    {
        $user = User::factory()->create();
        $kept = NewsSource::factory()->create();
        $hidden = NewsSource::factory()->create();
        $keptArticle = NewsArticle::factory()->create(['news_source_id' => $kept->id, 'published_at' => now()]);
        NewsArticle::factory()->create(['news_source_id' => $hidden->id, 'published_at' => now()]);

        $this->actingAs($user)->put("/settings/sources/{$hidden->id}", ['hidden' => true])->assertRedirect();

        $this->actingAs($user)->get('/feed')
            ->assertInertia(fn (Assert $page) => $page->has('articles', 1)->where('articles.0.id', $keptArticle->id));

        $this->actingAs(User::factory()->create())->get('/feed')
            ->assertInertia(fn (Assert $page) => $page->has('articles', 2));
    }

    public function test_hiding_is_undone_by_showing_the_source_again(): void
    {
        $user = User::factory()->create();
        $source = NewsSource::factory()->create();

        $this->actingAs($user)->put("/settings/sources/{$source->id}", ['hidden' => true]);
        $this->actingAs($user)->put("/settings/sources/{$source->id}", ['hidden' => false]);

        $this->assertSame(0, UserHiddenSource::query()->count());
    }

    public function test_the_usage_page_counts_this_months_calls_by_task_and_who_paid(): void
    {
        $user = User::factory()->create();
        AiUsage::query()->create(['user_id' => $user->id, 'task' => 'conversation', 'provider' => 'openai', 'paid_by' => 'user', 'input_tokens' => 10, 'output_tokens' => 4, 'ok' => true, 'created_at' => now()]);
        AiUsage::query()->create(['user_id' => $user->id, 'task' => 'conversation', 'provider' => 'gemini', 'paid_by' => 'platform', 'ok' => false, 'created_at' => now()]);
        AiUsage::query()->create(['user_id' => $user->id, 'task' => 'tts', 'provider' => 'openai', 'paid_by' => 'user', 'ok' => true, 'created_at' => now()->subMonth()->startOfMonth()]);

        $this->actingAs($user)->get('/settings/usage')
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/Usage')
                ->where('month', now()->format('Y-m'))
                ->where('cap', null)
                ->where('used', 2)
                ->where('by_task.0.task', 'conversation')
                ->where('by_task.0.calls', 2)
                ->where('by_task.0.paid_by_user', 1)
                ->where('by_task.0.paid_by_platform', 1)
                ->where('by_task.0.input_tokens', 10)
                ->where('by_task.0.output_tokens', 4)
                ->where('by_task.3.task', 'tts')
                ->where('by_task.3.calls', 0)
                ->has('recent', 3)
                ->where('recent.0.task', 'conversation')
                ->missing('recent.0.key'));
    }

    public function test_the_monthly_cap_is_set_and_cleared(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/usage/cap', ['cap' => 50])->assertRedirect();
        $this->assertSame(50, $user->fresh()->ai_monthly_cap);

        $this->actingAs($user)->put('/settings/usage/cap', ['cap' => null])->assertRedirect();
        $this->assertNull($user->fresh()->ai_monthly_cap);
    }

    public function test_a_cap_must_be_a_positive_number_of_calls(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/settings/usage/cap', ['cap' => 0])->assertSessionHasErrors('cap');
        $this->actingAs($user)->put('/settings/usage/cap', ['cap' => 'many'])->assertSessionHasErrors('cap');
        $this->actingAs($user)->put('/settings/usage/cap', [])->assertSessionHasErrors('cap');
    }
}
