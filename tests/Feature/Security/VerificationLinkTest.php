<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The link in the verification email is the only way to verify an address, so every variant that is
 * not "a fresh signed link for this account" must be refused or do nothing.
 */
class VerificationLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_link_without_a_signature_is_refused(): void
    {
        $user = User::factory()->unverified()->create();
        $link = route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]);

        $this->actingAs($user)->get($link)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_expired_link_is_refused(): void
    {
        $user = User::factory()->unverified()->create();
        $link = URL::temporarySignedRoute('verification.verify', now()->subMinute(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($link)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_link_for_another_account_is_refused(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $other->id,
            'hash' => sha1($other->email),
        ]);

        $this->actingAs($user)->get($link)->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_a_guest_is_sent_to_log_in_and_the_link_verifies_nothing(): void
    {
        $user = User::factory()->unverified()->create();
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->get($link)->assertRedirect('/login');

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_verified_user_following_the_link_keeps_the_original_date(): void
    {
        $verifiedAt = now()->subDays(3)->startOfSecond();
        $user = User::factory()->create(['email_verified_at' => $verifiedAt]);
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($link)->assertRedirect('/feed');

        $this->assertTrue($user->fresh()->email_verified_at->equalTo($verifiedAt));
    }

    public function test_asking_for_the_email_again_once_verified_sends_nothing(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post('/email/verification-notification')->assertRedirect('/feed');

        Notification::assertNothingSent();
    }
}
