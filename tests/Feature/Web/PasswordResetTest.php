<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const LINK_SENT = 'If an account exists for that email, we have sent it a link to reset the password.';

    public function test_the_forgot_password_page_is_shown_to_guests(): void
    {
        $this->get('/forgot-password')->assertOk()->assertInertia(fn (Assert $page) => $page->component('auth/ForgotPassword'));
    }

    public function test_a_reset_link_is_emailed_to_the_user(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ana@example.com']);

        $this->from('/forgot-password')
            ->post('/forgot-password', ['email' => 'ana@example.com'])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', self::LINK_SENT);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;

            return $url === url("/reset-password/{$notification->token}?email=ana%40example.com");
        });
    }

    public function test_the_answer_does_not_reveal_whether_an_account_exists(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status', self::LINK_SENT);
        $this->post('/forgot-password', ['email' => 'not-an-email'])->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_the_reset_page_receives_the_token_and_email(): void
    {
        $this->get('/reset-password/some-token?email=ana%40example.com')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/ResetPassword')
                ->where('token', 'some-token')
                ->where('email', 'ana@example.com'));
    }

    public function test_the_password_can_be_reset_with_the_emailed_token(): void
    {
        Notification::fake();
        Event::fake([PasswordReset::class]);
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        $this->post('/forgot-password', ['email' => $user->email]);
        $token = Notification::sent($user, ResetPassword::class)->sole()->token;

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect('/login')->assertSessionHas('status', 'Your password has been reset.');

        $user->refresh();
        $this->assertTrue(Hash::check('a-brand-new-password', $user->password));
        $this->assertNotSame('old-remember-token', $user->remember_token);
        Event::assertDispatched(PasswordReset::class, fn (PasswordReset $event) => $event->user->is($user));

        $this->post('/login', ['email' => $user->email, 'password' => 'a-brand-new-password'])->assertRedirect('/feed');
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_invalid_token_does_not_change_the_password(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'forged-token',
            'email' => $user->email,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors(['email' => 'This password reset token is invalid.']);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_a_token_can_only_be_used_once(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);
        $token = Notification::sent($user, ResetPassword::class)->sole()->token;
        $reset = fn (string $password) => $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => $password,
            'password_confirmation' => $password,
        ]);

        $reset('first-new-password')->assertSessionHasNoErrors();
        $reset('second-new-password')->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('first-new-password', $user->fresh()->password));
    }
}
