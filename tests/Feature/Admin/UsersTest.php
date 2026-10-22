<?php

namespace Tests\Feature\Admin;

use App\Models\AiUsage;
use App\Models\User;
use App\Models\UserAiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ConfiguresUserAi;
use Tests\TestCase;

/**
 * Platform → Users: each account with this month's calls and failures, whether it has a key, and whether it may use the
 * platform's connection. Only the owner's own admin rights are protected from the owner's own hand.
 */
class UsersTest extends TestCase
{
    use ConfiguresUserAi;
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

    public function test_the_users_page_is_for_admins_only(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/users')->assertForbidden();
    }

    public function test_the_page_lists_each_user_with_this_months_calls_and_no_keys_or_content(): void
    {
        $admin = User::factory()->admin()->withoutAi()->create();
        $withKey = $this->userWithAi('openai', 'sk-user-secret-9999');
        AiUsage::query()->create(['user_id' => $withKey->id, 'task' => 'conversation', 'provider' => 'openai', 'paid_by' => 'user', 'ok' => true, 'created_at' => now()]);
        AiUsage::query()->create(['user_id' => $withKey->id, 'task' => 'stt', 'provider' => 'openai', 'paid_by' => 'user', 'ok' => false, 'created_at' => now()]);
        AiUsage::query()->create(['user_id' => $withKey->id, 'task' => 'stt', 'provider' => 'openai', 'paid_by' => 'user', 'ok' => true, 'created_at' => now()->subMonths(2)]);
        $without = User::factory()->withoutAi()->create();

        $this->actingAs($admin)->get('/admin/users')
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Users')
                ->has('users', 3)
                ->where('users.1.id', $withKey->id)
                ->where('users.1.has_key', true)
                ->where('users.1.month_calls', 2)
                ->where('users.1.month_errors', 1)
                ->where('users.2.id', $without->id)
                ->where('users.2.has_key', false)
                ->missing('users.1.key')
                ->missing('users.1.conversations'))
            ->assertDontSee('sk-user-secret-9999', escape: false);
    }

    public function test_the_owner_may_allow_a_user_the_platform_and_take_it_back(): void
    {
        $admin = User::factory()->admin()->withoutAi()->create();
        $user = User::factory()->withoutAi()->create();

        $this->actingAs($admin)->put("/admin/users/{$user->id}", ['platform_ai' => true])->assertRedirect();
        $this->assertTrue($user->fresh()->platform_ai);

        $this->actingAs($admin)->put("/admin/users/{$user->id}", ['platform_ai' => false])->assertRedirect();
        $this->assertFalse($user->fresh()->platform_ai);
    }

    public function test_an_admin_cannot_take_their_own_admin_rights_away(): void
    {
        $admin = User::factory()->admin()->withoutAi()->create();

        $this->actingAs($admin)->put("/admin/users/{$admin->id}", ['is_admin' => false])
            ->assertSessionHasErrors(['is_admin' => 'You cannot remove your own admin rights.']);

        $this->assertTrue($admin->fresh()->is_admin);
    }

    public function test_an_admin_can_make_another_user_an_admin(): void
    {
        $admin = User::factory()->admin()->withoutAi()->create();
        $user = User::factory()->withoutAi()->create();

        $this->actingAs($admin)->put("/admin/users/{$user->id}", ['is_admin' => true])->assertRedirect();

        $this->assertTrue($user->fresh()->is_admin);
    }

    public function test_an_admin_cannot_delete_their_own_account_here(): void
    {
        $admin = User::factory()->admin()->withoutAi()->create();

        $this->actingAs($admin)->delete("/admin/users/{$admin->id}")
            ->assertSessionHasErrors('delete');

        $this->assertNotNull($admin->fresh());
    }

    public function test_an_admin_can_erase_another_account_as_its_owner_would(): void
    {
        $admin = User::factory()->admin()->withoutAi()->create();
        $user = $this->userWithAi();

        $this->actingAs($admin)->delete("/admin/users/{$user->id}")->assertRedirect();

        $this->assertNull(User::query()->find($user->id));
        $this->assertSame(0, UserAiKey::query()->where('user_id', $user->id)->count());
    }

    public function test_the_overview_counts_the_users_and_those_with_a_key(): void
    {
        $admin = User::factory()->admin()->withoutAi()->create();
        $this->userWithAi();
        User::factory()->withoutAi()->create();

        $this->actingAs($admin)->get('/admin')
            ->assertInertia(fn (Assert $page) => $page
                ->where('cards.users.total', 3)
                ->where('cards.users.with_key', 1));
    }
}
