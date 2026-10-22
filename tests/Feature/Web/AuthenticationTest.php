<?php

namespace Tests\Feature\Web;

use App\Enums\EnglishLevel;
use App\Models\User;
use App\Http\Middleware\HandleInertiaRequests;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\OpensSignUp;
use Tests\Support\SolvesAltcha;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use OpensSignUp, RefreshDatabase, SolvesAltcha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->signUpIsOpen();
    }

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

        $this->post('/register', $this->withAltcha([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'current_level' => 'C1',
            'consent' => true,
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
        ]))->assertRedirect('/email/verify');

        $user = User::firstWhere('email', 'ada@example.com');
        $this->assertSame(EnglishLevel::C1, $user->current_level);
        $this->assertAuthenticatedAs($user);
    }

    public function test_sign_up_is_validated(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', $this->withAltcha([
            'name' => '',
            'email' => 'taken@example.com',
            'current_level' => 'A1',
            'consent' => true,
            'password' => 'one',
            'password_confirmation' => 'two',
        ]))->assertSessionHasErrors(['name', 'email', 'current_level', 'password']);

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

    public function test_passwords_need_at_least_twelve_characters(): void
    {
        $this->post('/register', $this->registration('ada@example.com', 'eleven-char'))
            ->assertSessionHasErrors(['password']);

        $this->assertGuest();
    }

    public function test_failed_logins_are_limited_per_email_and_address(): void
    {
        $user = User::factory()->create();

        foreach (range(1, AppServiceProvider::LOGINS_PER_EMAIL_AND_ADDRESS_PER_MINUTE) as $attempt) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertRedirect();
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertStatus(429);
    }

    public function test_failures_from_other_addresses_do_not_lock_the_owner_of_an_email_out(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery-staple']);

        foreach (range(1, AppServiceProvider::FAILED_LOGINS_PER_EMAIL_AND_ADDRESS_PER_HOUR) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.1.0.{$attempt}"])
                ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertRedirect();
        }

        // Someone who only knows the email cannot lock its owner out: the owner signs in from another address.
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.0.250'])
            ->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery-staple'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    public function test_failed_logins_from_one_address_stop_only_that_address_for_an_hour(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery-staple']);
        $guest = ['REMOTE_ADDR' => '10.1.0.1'];

        // Six wrong passwords a minute for five minutes: thirty failures from one address.
        foreach (range(1, 5) as $minute) {
            foreach (range(1, AppServiceProvider::LOGINS_PER_EMAIL_AND_ADDRESS_PER_MINUTE) as $attempt) {
                $this->withServerVariables($guest)
                    ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
                    ->assertRedirect();
            }

            $this->travel(2)->minutes();
        }

        // That address is refused for the rest of the hour, even with the right password.
        $this->withServerVariables($guest)
            ->post('/login', ['email' => $user->email, 'password' => 'correct-horse-battery-staple'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_one_address_can_open_only_a_limited_number_of_accounts_a_day(): void
    {
        config(['limits.registrations_per_ip_per_day' => 2]);

        foreach (['ana', 'ben'] as $name) {
            $this->post('/register', $this->registration("{$name}@example.com"))->assertRedirect('/email/verify');
            $this->post('/logout');
        }

        $this->post('/register', $this->registration('cy@example.com'))->assertStatus(429);
        $this->assertDatabaseMissing('users', ['email' => 'cy@example.com']);
    }

    public function test_logging_out_clears_the_inertia_history(): void
    {
        $this->actingAs(User::factory()->create())->post('/logout')->assertRedirect('/');

        $this->withHeaders($this->inertiaHeaders())->get('/')->assertJson(['clearHistory' => true]);
    }

    public function test_inertia_pages_encrypt_their_browser_history(): void
    {
        $this->withHeaders($this->inertiaHeaders())->get('/login')->assertJson(['encryptHistory' => true]);
    }

    /**
     * The headers an Inertia visit sends, including the asset version the server expects.
     *
     * @return array<string, string>
     */
    private function inertiaHeaders(): array
    {
        return [
            'X-Inertia' => 'true',
            // As a string: without a built manifest the version is null, and Inertia compares it as ''.
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function registration(string $email, string $password = 'a-strong-password'): array
    {
        return $this->withAltcha([
            'name' => 'Ada Lovelace',
            'email' => $email,
            'current_level' => 'C1',
            'password' => $password,
            'password_confirmation' => $password,
            'consent' => true,
        ]);
    }
}
