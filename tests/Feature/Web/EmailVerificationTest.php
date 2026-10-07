<?php

namespace Tests\Feature\Web;

use App\Enums\EnglishLevel;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_account_gets_a_verification_email_and_waits_for_it(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
            'current_level' => EnglishLevel::B2->value,
        ])->assertRedirect('/email/verify');

        Notification::assertSentTo(User::where('email', 'ana@example.com')->sole(), VerifyEmail::class);
    }

    public function test_unverified_users_cannot_use_the_app_until_they_verify(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/feed')->assertRedirect('/email/verify');
        $this->actingAs($user)->get('/vocabulary')->assertRedirect('/email/verify');
        $this->actingAs($user)
            ->get('/email/verify')
            ->assertInertia(fn (Assert $page) => $page->component('auth/VerifyEmail')->where('email', $user->email));
    }

    public function test_unverified_users_cannot_spend_on_ai_through_the_api(): void
    {
        Sanctum::actingAs(User::factory()->unverified()->create());

        $this->postJson('/api/lookups', ['text' => 'nuance'])->assertForbidden();
        $this->postJson('/api/vocabulary', ['word' => 'nuance'])->assertForbidden();
    }

    public function test_the_link_in_the_email_verifies_the_address(): void
    {
        $user = User::factory()->unverified()->create();
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($link)->assertRedirect('/feed');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->actingAs($user)->get('/feed')->assertOk();
    }

    public function test_a_link_for_another_address_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1('someone@else.com')]);

        $this->actingAs($user)->get($link)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_the_email_can_be_sent_again(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->from('/email/verify')->post('/email/verification-notification')->assertRedirect('/email/verify');

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_verified_users_skip_the_verification_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/email/verify')->assertRedirect('/feed');
    }
}
