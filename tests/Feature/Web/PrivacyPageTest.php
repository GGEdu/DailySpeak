<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrivacyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_privacy_notice_is_public(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Privacy')->where('version', User::CONSENT_VERSION));

        $this->actingAs(User::factory()->create())->get('/privacy')->assertOk();
    }

    public function test_the_notice_states_the_retention_periods_from_the_configuration(): void
    {
        config([
            'debate.audio.retention_days' => 7,
            'privacy.replies_retention_days' => null,
            'privacy.transcripts_retention_days' => 0,
        ]);

        $this->get('/privacy')->assertInertia(fn (Assert $page) => $page
            ->where('retention.recordings', 7)
            ->where('retention.replies', 7)
            ->where('retention.transcripts', 0));

        config(['debate.audio.retention_days' => 3, 'privacy.replies_retention_days' => 2]);

        $this->get('/privacy')->assertInertia(fn (Assert $page) => $page
            ->where('retention.recordings', 3)
            ->where('retention.replies', 2));
    }
}
