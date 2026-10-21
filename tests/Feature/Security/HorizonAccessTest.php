<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Tests\TestCase;

/**
 * Horizon shows queue contents and lets its users retry or delete jobs. Outside the local environment only
 * administrators may open it.
 */
class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Horizon opens to everyone on a local machine, so the gate is only meaningful outside it.
        $this->assertFalse(app()->environment('local'));
    }

    public function test_guests_cannot_open_the_dashboard_or_its_api(): void
    {
        $this->get('/horizon')->assertForbidden();
        $this->getJson('/horizon/api/stats')->assertForbidden();
    }

    public function test_ordinary_users_cannot_open_it(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/horizon')->assertForbidden();
        $this->actingAs($user)->getJson('/horizon/api/stats')->assertForbidden();
    }

    public function test_administrators_can_open_it(): void
    {
        $admin = User::factory()->admin()->create();

        // The stats read Horizon's Redis repositories, which the suite does not run: empty ones stand in for them.
        foreach ([JobRepository::class, MetricsRepository::class, SupervisorRepository::class, MasterSupervisorRepository::class] as $contract) {
            $this->mock($contract, fn ($mock) => $mock->shouldIgnoreMissing());
        }

        $this->actingAs($admin)->get('/horizon')->assertOk();
        $this->actingAs($admin)->getJson('/horizon/api/stats')->assertOk()->assertJsonStructure(['failedJobs', 'jobsPerMinute', 'status']);
    }

    public function test_every_script_on_the_dashboard_carries_the_nonce_of_the_policy(): void
    {
        $admin = User::factory()->admin()->create();

        foreach ([JobRepository::class, MetricsRepository::class, SupervisorRepository::class, MasterSupervisorRepository::class] as $contract) {
            $this->mock($contract, fn ($mock) => $mock->shouldIgnoreMissing());
        }

        $response = $this->actingAs($admin)->get('/horizon')->assertOk();

        // Horizon's JavaScript is inline: under the policy it runs only with the nonce of this response.
        preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $policy);
        $this->assertNotEmpty($policy[1] ?? null);

        preg_match_all('/<script\b[^>]*>/i', $response->getContent(), $scripts);
        $this->assertNotEmpty($scripts[0]);

        foreach ($scripts[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$policy[1].'"', $tag);
        }
    }
}
