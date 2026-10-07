<?php

namespace Tests\Feature\Web;

use App\Enums\EnglishLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_lists_the_levels_with_their_descriptions(): void
    {
        $user = User::factory()->level(EnglishLevel::B2)->create();

        $this->actingAs($user)
            ->get('/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings')
                ->has('levels', 3)
                ->where('levels.0', [
                    'value' => 'B1',
                    'label' => 'Intermediate',
                    'description' => 'You can talk about familiar topics and give simple reasons for your opinions.',
                ])
                ->where('levels.2.label', 'Advanced')
                ->where('auth.user.current_level', 'B2'));
    }

    public function test_users_can_change_their_level(): void
    {
        $user = User::factory()->level(EnglishLevel::B2)->create();

        $this->actingAs($user)
            ->from('/settings')
            ->put('/settings', ['current_level' => 'C1'])
            ->assertRedirect('/settings')
            ->assertSessionHas('status', 'Your level is now C1. The tutor adapts from your next turn.');

        $this->assertSame(EnglishLevel::C1, $user->fresh()->current_level);
    }

    public function test_the_level_must_be_a_known_one(): void
    {
        $user = User::factory()->level(EnglishLevel::B2)->create();

        $this->actingAs($user)->put('/settings', ['current_level' => 'A1'])->assertSessionHasErrors('current_level');
        $this->actingAs($user)->put('/settings', [])->assertSessionHasErrors('current_level');

        $this->assertSame(EnglishLevel::B2, $user->fresh()->current_level);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/settings')->assertRedirect('/login');
        $this->put('/settings', ['current_level' => 'C1'])->assertRedirect('/login');
    }
}
