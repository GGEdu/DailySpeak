<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\AiBudget;
use App\Services\Limits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LimitsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/admin/limits')->assertRedirect('/login');
    }

    public function test_regular_users_cannot_open_or_change_the_limits(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/admin/limits')->assertForbidden();
        $this->actingAs($user)->put('/admin/limits/lookups_per_minute', ['value' => 1])->assertForbidden();
        $this->actingAs($user)->post('/admin/limits/lookups_per_minute/reset')->assertForbidden();

        $this->assertDatabaseEmpty('app_settings');
        $this->assertSame(40, app(Limits::class)->value('lookups_per_minute'));
    }

    public function test_admins_see_every_limit_with_its_value_and_its_default(): void
    {
        $admin = User::factory()->admin()->create();
        app(Limits::class)->save('lookups_per_minute', 15, $admin);

        $this->actingAs($admin)->get('/admin/limits')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Limits')
                ->has('limits', 8)
                ->where('limits.0.key', 'ai_daily_global')
                ->where('limits.0.value', 1000)
                ->where('limits.0.default', 1000)
                ->where('limits.0.customised', false)
                ->where('limits.4.key', 'lookups_per_minute')
                ->where('limits.4.value', 15)
                ->where('limits.4.default', 40)
                ->where('limits.4.customised', true)
                ->where('limits.4.max', 1000));
    }

    public function test_the_page_shows_today_use_of_the_global_ai_budget_and_when_it_resets(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();
        $budget = app(AiBudget::class);
        $budget->spend($user);
        $budget->spend($user);

        $this->actingAs($admin)->get('/admin/limits')
            ->assertInertia(fn (Assert $page) => $page
                ->where('aiBudget.used', 2)
                ->where('aiBudget.limit', 1000)
                ->where('aiBudget.resets_at', now()->addDay()->startOfDay()->format('Y-m-d H:i T')));
    }

    public function test_an_admin_saves_a_limit_and_it_applies_at_once(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/limits/feed_searches_per_minute', ['value' => 3])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseHas('app_settings', [
            'key' => 'feed_searches_per_minute',
            'value' => 3,
            'updated_by' => $admin->id,
        ]);
        $this->assertSame(3, app(Limits::class)->value('feed_searches_per_minute'));
    }

    public function test_an_admin_sets_a_limit_back_to_its_default(): void
    {
        $admin = User::factory()->admin()->create();
        app(Limits::class)->save('debate_finishes_per_minute', 9, $admin);

        $this->actingAs($admin)->post('/admin/limits/debate_finishes_per_minute/reset')
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('app_settings', ['key' => 'debate_finishes_per_minute']);
        $this->assertSame(5, app(Limits::class)->value('debate_finishes_per_minute'));
    }

    public function test_a_limit_must_be_a_whole_number_from_one_to_its_maximum(): void
    {
        $admin = User::factory()->admin()->create();

        foreach (['', '0', '-3', '2.5', 'many', '100001'] as $value) {
            $this->actingAs($admin)->put('/admin/limits/ai_daily_global', ['value' => $value])
                ->assertSessionHasErrors('value');
        }

        $this->assertDatabaseEmpty('app_settings');

        $this->actingAs($admin)->put('/admin/limits/ai_daily_global', ['value' => '100000'])
            ->assertSessionHasNoErrors();
        $this->assertSame(100000, app(Limits::class)->value('ai_daily_global'));
    }

    public function test_an_unknown_limit_is_not_found(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->put('/admin/limits/login_per_minute', ['value' => 1])->assertNotFound();
        $this->actingAs($admin)->post('/admin/limits/login_per_minute/reset')->assertNotFound();
    }

    public function test_every_change_is_logged_without_personal_data(): void
    {
        Log::spy();
        $admin = User::factory()->admin()->create(['email' => 'ada@example.com', 'name' => 'Ada Lovelace']);

        $this->actingAs($admin)->put('/admin/limits/lookups_per_day', ['value' => 250]);

        Log::shouldHaveReceived('info', ['Limit changed by an admin', [
            'admin_id' => $admin->id,
            'key' => 'lookups_per_day',
            'before' => 500,
            'after' => 250,
        ]])->once();
    }
}
