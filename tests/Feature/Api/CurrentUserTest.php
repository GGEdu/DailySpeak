<?php

namespace Tests\Feature\Api;

use App\Enums\EnglishLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CurrentUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_api_user_endpoint_returns_only_the_fields_the_app_needs(): void
    {
        Sanctum::actingAs(User::factory()->admin()->level(EnglishLevel::C1)->create(['name' => 'Grace']));

        $response = $this->getJson('/api/user')
            ->assertOk()
            ->assertJson(['name' => 'Grace', 'current_level' => 'C1']);

        $this->assertSame(['id', 'name', 'email', 'current_level'], array_keys($response->json()));
    }

    public function test_guests_cannot_read_the_api_user(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }
}
