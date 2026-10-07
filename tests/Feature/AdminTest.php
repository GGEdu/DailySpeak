<?php

namespace Tests\Feature;

use App\Models\Debate;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_regular_users_are_rate_limited_on_voice_turns(): void
    {
        $debate = $this->debateFor(User::factory()->create());

        $statuses = $this->uploadTurns($debate, AppServiceProvider::AUDIO_UPLOADS_PER_MINUTE + 1);

        $this->assertSame(array_fill(0, AppServiceProvider::AUDIO_UPLOADS_PER_MINUTE, 202), array_slice($statuses, 0, -1));
        $this->assertSame(429, last($statuses));
    }

    public function test_admins_have_no_voice_turn_limit(): void
    {
        $debate = $this->debateFor(User::factory()->admin()->create());

        $statuses = $this->uploadTurns($debate, AppServiceProvider::AUDIO_UPLOADS_PER_MINUTE + 5);

        $this->assertSame([202], array_values(array_unique($statuses)));
    }

    public function test_the_command_grants_and_revokes_admin_rights(): void
    {
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->artisan('user:admin', ['email' => 'ada@example.com'])->assertSuccessful();
        $this->assertTrue($user->fresh()->is_admin);

        $this->artisan('user:admin', ['email' => 'ada@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertFalse($user->fresh()->is_admin);

        $this->artisan('user:admin', ['email' => 'nobody@example.com'])->assertFailed();
    }

    public function test_admin_rights_cannot_be_self_assigned_when_signing_up(): void
    {
        $this->post('/register', [
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'current_level' => 'B2',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
            'is_admin' => true,
        ])->assertRedirect('/feed');

        $this->assertFalse(User::firstWhere('email', 'mallory@example.com')->is_admin);
    }

    public function test_only_admins_can_open_horizon_outside_local(): void
    {
        $this->assertTrue(Gate::forUser(User::factory()->admin()->create())->allows('viewHorizon'));
        $this->assertFalse(Gate::forUser(User::factory()->create())->allows('viewHorizon'));
    }

    private function debateFor(User $user): Debate
    {
        Storage::fake('local');
        Queue::fake();
        Sanctum::actingAs($user);

        return Debate::factory()->for($user)->create();
    }

    /**
     * @return list<int>
     */
    private function uploadTurns(Debate $debate, int $times): array
    {
        return array_map(
            fn () => $this->postJson("/api/debates/{$debate->id}/audio", [
                'audio' => UploadedFile::fake()->create('turn.webm', 10, 'audio/webm'),
            ])->status(),
            range(1, $times),
        );
    }
}
