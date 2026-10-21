<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Accounts created before email verification existed must keep working after deploy: the migration marks
 * them verified. The migration is run by hand here, against the rows the test creates.
 */
class MarkExistingUsersAsVerifiedTest extends TestCase
{
    use RefreshDatabase;

    public function test_up_verifies_the_accounts_that_were_waiting_and_keeps_the_others_as_they_were(): void
    {
        $waiting = User::factory()->unverified()->create();
        $verifiedAt = Carbon::parse('2026-09-01 09:30:00');
        $verified = User::factory()->create(['email_verified_at' => $verifiedAt]);

        $this->migration()->up();

        $this->assertTrue($waiting->fresh()->hasVerifiedEmail());
        $this->assertTrue($verified->fresh()->email_verified_at->equalTo($verifiedAt));
    }

    public function test_down_changes_nothing(): void
    {
        $waiting = User::factory()->unverified()->create();
        $verifiedAt = Carbon::parse('2026-09-01 09:30:00');
        $verified = User::factory()->create(['email_verified_at' => $verifiedAt]);

        $this->migration()->up();
        $markedAt = $waiting->fresh()->email_verified_at;

        $this->migration()->down();

        $this->assertTrue($waiting->fresh()->email_verified_at->equalTo($markedAt));
        $this->assertTrue($verified->fresh()->email_verified_at->equalTo($verifiedAt));
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_10_08_130000_mark_existing_users_as_verified.php');
    }
}
