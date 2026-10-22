<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Support\OpensSignUp;
use Tests\TestCase;

/**
 * Each authentication form takes six attempts a minute from the same place; the seventh is refused.
 *
 * The tests check the behaviour, not the name of the limiter or its key, so changing what is counted
 * (for example, per email and IP) does not change them. Every attempt uses the same email and IP.
 */
class AuthenticationThrottleTest extends TestCase
{
    use OpensSignUp, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signUpIsOpen();
    }

    private const EMAIL = 'grace@example.com';

    private const PASSWORD = 'correct-horse-battery-staple';

    public function test_login_refuses_the_seventh_attempt_in_a_minute(): void
    {
        User::factory()->create(['email' => self::EMAIL, 'password' => self::PASSWORD]);

        $this->assertSixAttemptsThenRefused(fn () => $this->post('/login', [
            'email' => self::EMAIL,
            'password' => 'wrong-'.self::PASSWORD,
        ]));
    }

    public function test_registration_refuses_the_seventh_attempt_in_a_minute(): void
    {
        // The passwords never match, so nobody is signed in and every attempt is counted.
        $this->assertSixAttemptsThenRefused(fn () => $this->post('/register', [
            'name' => 'Grace',
            'email' => self::EMAIL,
            'current_level' => 'B2',
            'password' => self::PASSWORD,
            'password_confirmation' => 'something-else-entirely',
        ]));
    }

    public function test_forgot_password_refuses_the_seventh_attempt_in_a_minute(): void
    {
        Notification::fake();
        User::factory()->create(['email' => self::EMAIL]);

        $this->assertSixAttemptsThenRefused(fn () => $this->post('/forgot-password', ['email' => self::EMAIL]));
    }

    public function test_reset_password_refuses_the_seventh_attempt_in_a_minute(): void
    {
        $this->assertSixAttemptsThenRefused(fn () => $this->post('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => self::EMAIL,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]));
    }

    public function test_the_verification_link_refuses_the_seventh_attempt_in_a_minute(): void
    {
        $user = User::factory()->unverified()->create(['email' => self::EMAIL]);
        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->assertSixAttemptsThenRefused(fn () => $this->actingAs($user)->get($link));
    }

    public function test_resending_the_verification_email_refuses_the_seventh_attempt_in_a_minute(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => self::EMAIL]);

        $this->assertSixAttemptsThenRefused(fn () => $this->actingAs($user)->post('/email/verification-notification'));
    }

    /**
     * @param  callable(): TestResponse  $attempt
     */
    private function assertSixAttemptsThenRefused(callable $attempt): void
    {
        for ($i = 0; $i < 6; $i++) {
            $attempt()->assertRedirect();
        }

        $attempt()->assertTooManyRequests();

        // The window is one minute: once it has passed, the form takes requests again.
        $this->travel(2)->minutes();
        $attempt()->assertRedirect();
    }
}
