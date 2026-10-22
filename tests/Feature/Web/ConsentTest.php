<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\Support\SolvesAltcha;
use Tests\TestCase;

class ConsentTest extends TestCase
{
    use RefreshDatabase, SolvesAltcha;

    public function test_registration_needs_the_consent_box_ticked(): void
    {
        $this->post('/register', $this->withAltcha($this->registration('ada@example.com', consent: false)))
            ->assertSessionHasErrors('consent');

        $this->post('/register', $this->withAltcha($this->registration('ada@example.com', consent: null)))
            ->assertSessionHasErrors('consent');

        $this->assertDatabaseMissing('users', ['email' => 'ada@example.com']);
    }

    public function test_registration_records_when_and_which_notice_the_user_accepted(): void
    {
        $this->post('/register', $this->withAltcha($this->registration('ada@example.com')))
            ->assertSessionHasNoErrors();

        $user = User::where('email', 'ada@example.com')->sole();
        $this->assertNotNull($user->consented_at);
        $this->assertEqualsWithDelta(now()->timestamp, $user->consented_at->timestamp, 60);
        $this->assertSame(User::CONSENT_VERSION, $user->consent_version);
        $this->assertTrue($user->hasCurrentConsent());
    }

    public function test_a_user_without_consent_is_sent_to_the_consent_page(): void
    {
        $user = User::factory()->withoutConsent()->create();

        $this->actingAs($user)->get('/feed')->assertRedirect('/consent');
        $this->actingAs($user)->get('/vocabulary')->assertRedirect('/consent');
    }

    public function test_a_notice_accepted_in_an_older_version_is_asked_again(): void
    {
        $user = User::factory()->create(['consent_version' => '2020-01-01']);

        $this->actingAs($user)->get('/feed')->assertRedirect('/consent');
    }

    public function test_without_consent_the_user_can_still_download_their_data(): void
    {
        $user = User::factory()->withoutConsent()->create();

        $this->actingAs($user)->get('/settings/export')
            ->assertOk()
            ->assertJsonPath('profile.email', $user->email);
    }

    public function test_without_consent_the_user_can_still_delete_the_account(): void
    {
        $user = User::factory()->withoutConsent()->create(['password' => 'a-strong-password']);

        $this->actingAs($user)->delete('/settings/account', ['password' => 'a-strong-password'])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_without_consent_the_user_can_still_log_out(): void
    {
        $user = User::factory()->withoutConsent()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');
    }

    public function test_the_settings_page_stays_open_so_the_user_can_manage_their_data(): void
    {
        $user = User::factory()->withoutConsent()->create();

        $this->actingAs($user)->get('/settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Settings'));
    }

    public function test_accepting_the_notice_unlocks_the_app(): void
    {
        $user = User::factory()->withoutConsent()->create();

        $this->actingAs($user)->get('/consent')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/Consent')->where('version', User::CONSENT_VERSION));

        $this->actingAs($user)->post('/consent', ['consent' => true])->assertRedirect('/feed');

        $user->refresh();
        $this->assertTrue($user->hasCurrentConsent());
        $this->assertNotNull($user->consented_at);
        $this->assertSame(User::CONSENT_VERSION, $user->consent_version);
        $this->actingAs($user)->get('/feed')->assertOk();
    }

    public function test_accepting_requires_the_box_to_be_ticked(): void
    {
        $user = User::factory()->withoutConsent()->create();

        $this->actingAs($user)->post('/consent', [])->assertSessionHasErrors('consent');
        $this->actingAs($user)->post('/consent', ['consent' => false])->assertSessionHasErrors('consent');

        $this->assertNull($user->fresh()->consented_at);
    }

    public function test_the_api_refuses_ai_requests_until_the_notice_is_accepted(): void
    {
        $user = User::factory()->withoutConsent()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/lookups', ['text' => 'lapse'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Accept the privacy notice to use DailySpeak.');
    }

    public function test_the_consent_page_is_for_signed_in_users(): void
    {
        $this->get('/consent')->assertRedirect('/login');
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(string $email, ?bool $consent = true): array
    {
        return [
            'name' => 'Ada Lovelace',
            'email' => $email,
            'current_level' => 'C1',
            'password' => 'a-strong-password',
            'password_confirmation' => 'a-strong-password',
            'consent' => $consent,
        ];
    }
}
