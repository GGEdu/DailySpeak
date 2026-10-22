<?php

namespace Tests\Feature\Services;

use App\Ai\Agents\WordExplainer;
use App\Models\Debate;
use App\Models\User;
use App\Services\Limits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Api\WordLookupTest;
use Tests\TestCase;

class LimitsEnforcementTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_global_ai_limit_saved_as_one_refuses_the_second_call_with_429(): void
    {
        app(Limits::class)->save('ai_daily_global', 1, User::factory()->admin()->create());
        WordExplainer::fake([WordLookupTest::EXPLANATION])->preventStrayPrompts();
        // The budget counts the calls the platform pays for: this user is allowed the platform and picked it.
        $user = User::factory()->create();
        $user->forceFill(['platform_ai' => true])->save();
        $user->aiTasks()->update(['provider' => 'platform']);
        Sanctum::actingAs($user);

        $this->postJson('/api/lookups', ['text' => 'nuance'])->assertOk();
        $this->postJson('/api/lookups', ['text' => 'lapse'])->assertStatus(429);
    }

    public function test_a_saved_voice_turn_limit_per_minute_is_enforced(): void
    {
        Storage::fake('local');
        Queue::fake();
        app(Limits::class)->save('voice_turns_per_minute', 2, User::factory()->admin()->create());
        $user = User::factory()->create();
        $debate = Debate::factory()->for($user)->create();
        Sanctum::actingAs($user);

        $statuses = array_map(fn () => $this->postJson("/api/debates/{$debate->id}/audio", [
            'audio' => UploadedFile::fake()->create('turn.webm', 10, 'audio/webm'),
        ])->status(), range(1, 3));

        $this->assertSame([202, 202, 429], $statuses);
    }

    public function test_a_saved_lookup_limit_per_minute_is_enforced(): void
    {
        app(Limits::class)->save('lookups_per_minute', 2, User::factory()->admin()->create());
        WordExplainer::fake([WordLookupTest::EXPLANATION])->preventStrayPrompts();
        Sanctum::actingAs(User::factory()->create());

        // Cached after the first one, so only the limiter can stop the third.
        $this->postJson('/api/lookups', ['text' => 'nuance'])->assertOk();
        $this->postJson('/api/lookups', ['text' => 'nuance'])->assertOk();
        $this->postJson('/api/lookups', ['text' => 'nuance'])->assertTooManyRequests();
    }

    public function test_a_saved_feed_search_limit_is_enforced(): void
    {
        app(Limits::class)->save('feed_searches_per_minute', 2, User::factory()->admin()->create());
        $user = User::factory()->create();

        $this->actingAs($user)->get('/feed?q=madrid')->assertOk();
        $this->actingAs($user)->get('/feed?q=madrid')->assertOk();
        $this->actingAs($user)->get('/feed?q=madrid')->assertStatus(429);
    }

    public function test_a_saved_debate_report_limit_is_enforced(): void
    {
        Queue::fake();
        app(Limits::class)->save('debate_finishes_per_minute', 1, User::factory()->admin()->create());
        $debate = Debate::factory()->create();

        $this->actingAs($debate->user)->post("/debates/{$debate->id}/finish")->assertRedirect();
        $this->actingAs($debate->user)->post("/debates/{$debate->id}/finish")->assertTooManyRequests();
    }

    public function test_the_registration_limiter_uses_the_saved_value(): void
    {
        app(Limits::class)->save('registrations_per_ip_per_day', 3, User::factory()->admin()->create());

        $limit = RateLimiter::limiter('register')(Request::create('/register', 'POST'));

        $this->assertSame(3, $limit->maxAttempts);
    }

    public function test_the_lookup_limiters_use_the_saved_values(): void
    {
        $admin = User::factory()->admin()->create();
        $limits = app(Limits::class);
        $limits->save('lookups_per_minute', 5, $admin);
        $limits->save('lookups_per_day', 50, $admin);

        [$perMinute, $perDay] = RateLimiter::limiter('lookups')(Request::create('/api/lookups', 'POST'));

        $this->assertSame([5, 50], [$perMinute->maxAttempts, $perDay->maxAttempts]);
    }

    public function test_the_voice_turn_limiters_use_the_saved_values(): void
    {
        $admin = User::factory()->admin()->create();
        $limits = app(Limits::class);
        $limits->save('voice_turns_per_minute', 4, $admin);
        $limits->save('voice_turns_per_day', 6, $admin);

        [$perMinute, $perDay] = RateLimiter::limiter('debate-audio')(Request::create('/api/debates/1/audio', 'POST'));

        $this->assertSame([4, 6], [$perMinute->maxAttempts, $perDay->maxAttempts]);
    }
}
