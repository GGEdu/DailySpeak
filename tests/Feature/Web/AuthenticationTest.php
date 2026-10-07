<?php

namespace Tests\Feature\Web;

use App\Enums\EnglishLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_the_landing_page(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Welcome'));
    }

    public function test_signed_in_users_go_straight_to_the_feed(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/feed');
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/feed')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/Login'));
    }

    public function test_users_can_log_in_and_out(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/feed');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_wrong_credentials_are_rejected(): void
    {
        $user = User::factory()->create();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);

        $this->assertGuest();
    }

    public function test_visitors_can_sign_up_with_their_level(): void
    {
        $this->get('/register')->assertInertia(fn (Assert $page) => $page
            ->component('auth/Register')
            ->where('levels', ['B1', 'B2', 'C1']));

        $this->post('/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'current_level' => 'C1',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ])->assertRedirect('/feed');

        $user = User::firstWhere('email', 'ada@example.com');
        $this->assertSame(EnglishLevel::C1, $user->current_level);
        $this->assertAuthenticatedAs($user);
    }

    public function test_sign_up_is_validated(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', [
            'name' => '',
            'email' => 'taken@example.com',
            'current_level' => 'A1',
            'password' => 'one',
            'password_confirmation' => 'two',
        ])->assertSessionHasErrors(['name', 'email', 'current_level', 'password']);

        $this->assertGuest();
    }

    public function test_the_signed_in_user_is_shared_with_every_page(): void
    {
        $user = User::factory()->level(EnglishLevel::B2)->create(['name' => 'Grace']);

        $this->actingAs($user)->get('/feed')->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.name', 'Grace')
            ->where('auth.user.current_level', 'B2')
            ->missing('auth.user.password'));
    }
}
