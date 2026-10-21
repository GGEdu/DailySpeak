<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_unauthorized(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_unverified_users_are_refused(): void
    {
        Sanctum::actingAs(User::factory()->unverified()->create());

        $this->getJson('/api/user')->assertForbidden();
    }

    public function test_the_user_is_returned_without_the_password_or_the_remember_token(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery-staple']);
        Sanctum::actingAs($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('email', $user->email)
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('remember_token');
    }
}
