<?php

namespace Tests\Feature\Captcha;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha;
use AltchaOrg\Altcha\CreateChallengeOptions;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Support\SolvesAltcha;
use Tests\TestCase;

class AltchaCheckTest extends TestCase
{
    use RefreshDatabase, SolvesAltcha;

    public function test_the_challenge_asks_for_the_configured_work_and_is_not_cached(): void
    {
        $response = $this->get('/altcha/challenge')->assertOk();

        $response->assertJsonPath('parameters.algorithm', 'PBKDF2/SHA-256')
            ->assertJsonPath('parameters.cost', (int) config('altcha.cost'))
            ->assertJsonPath('parameters.keyLength', 32)
            ->assertJsonStructure(['parameters' => ['nonce', 'salt', 'keyPrefix', 'expiresAt'], 'signature']);

        $this->assertSame(2 * (int) config('altcha.key_prefix_bytes'), strlen($response->json('parameters.keyPrefix')));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_challenge_endpoint_is_throttled(): void
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $this->get('/altcha/challenge')->assertOk();
        }

        $this->get('/altcha/challenge')->assertStatus(429);
    }

    public function test_signed_in_users_are_not_offered_a_challenge(): void
    {
        $this->actingAs(User::factory()->create())->get('/altcha/challenge')->assertRedirect('/');
    }

    public function test_registration_without_a_solved_check_is_refused(): void
    {
        $this->post('/register', $this->registration('ada@example.com'))
            ->assertSessionHasErrors('altcha');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_registration_with_a_valid_solution_is_accepted(): void
    {
        $this->post('/register', $this->withAltcha($this->registration('ada@example.com')))
            ->assertRedirect('/email/verify')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
    }

    public function test_registration_with_a_tampered_solution_is_refused(): void
    {
        $payload = $this->tampered($this->solvedAltcha(), fn (array $data) => [
            ...$data,
            'solution' => [...$data['solution'], 'counter' => $data['solution']['counter'] + 1],
        ]);

        $this->post('/register', [...$this->registration('ada@example.com'), 'altcha' => $payload])
            ->assertSessionHasErrors('altcha');

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_registration_with_a_challenge_signed_by_another_key_is_refused(): void
    {
        $foreign = (new Altcha('a secret this server does not know'))->createChallenge(new CreateChallengeOptions(
            algorithm: new Pbkdf2,
            cost: 10,
            keyPrefix: '',
            keyPrefixLength: 1,
            expiresAt: time() + 600,
        ));

        $this->post('/register', [...$this->registration('ada@example.com'), 'altcha' => $this->solvedAltcha($foreign)])
            ->assertSessionHasErrors('altcha');

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_registration_with_an_expired_solution_is_refused(): void
    {
        config(['altcha.expires_in' => -60]);
        $payload = $this->solvedAltcha();

        $this->post('/register', [...$this->registration('ada@example.com'), 'altcha' => $payload])
            ->assertSessionHasErrors('altcha');

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_malformed_payloads_are_refused_without_a_server_error(): void
    {
        foreach (['not-a-payload', base64_encode('[1,2]'), base64_encode('{"challenge":1,"solution":2}'), str_repeat('A', 40), ['array']] as $payload) {
            $this->post('/register', [...$this->registration('ada@example.com'), 'altcha' => $payload])
                ->assertSessionHasErrors('altcha');
        }

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_a_solution_is_accepted_once(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ada@example.com']);
        $payload = $this->solvedAltcha();

        $this->post('/forgot-password', ['email' => $user->email, 'altcha' => $payload])
            ->assertSessionHas('status')
            ->assertSessionHasNoErrors();

        $this->post('/forgot-password', ['email' => $user->email, 'altcha' => $payload])
            ->assertSessionHasErrors('altcha');

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_a_check_is_not_used_up_by_a_form_with_other_errors(): void
    {
        $payload = $this->solvedAltcha();
        $data = [...$this->registration('ada@example.com'), 'altcha' => $payload];

        $this->post('/register', [...$data, 'password_confirmation' => 'does-not-match'])
            ->assertSessionHasErrors('password')
            ->assertSessionDoesntHaveErrors('altcha');

        $this->post('/register', $data)
            ->assertRedirect('/email/verify')
            ->assertSessionHasNoErrors();
    }

    public function test_forgot_password_sends_nothing_without_a_solved_check(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'ada@example.com']);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertSessionHasErrors('altcha');

        Notification::assertNothingSent();
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $edit
     */
    private function tampered(string $payload, callable $edit): string
    {
        return base64_encode(json_encode($edit(json_decode(base64_decode($payload), true)), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(string $email): array
    {
        return [
            'name' => 'Ada Lovelace',
            'email' => $email,
            'current_level' => 'C1',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
            'consent' => true,
        ];
    }
}
