<?php

namespace Tests\Feature\Models;

use App\Enums\EnglishLevel;
use App\Models\Debate;
use App\Models\User;
use App\Models\UserVocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_level_is_cast_to_enum(): void
    {
        $user = User::factory()->level(EnglishLevel::C1)->create();

        $this->assertSame(EnglishLevel::C1, $user->fresh()->current_level);
    }

    public function test_current_level_defaults_to_b2(): void
    {
        $user = User::create([
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'secret-password',
        ]);

        $this->assertSame(EnglishLevel::B2, $user->current_level);
        $this->assertSame(EnglishLevel::B2, $user->fresh()->current_level);
    }

    public function test_user_has_many_debates_and_vocabularies(): void
    {
        $user = User::factory()->create();
        Debate::factory(2)->for($user)->create();
        UserVocabulary::factory(3)->for($user)->create();

        $this->assertCount(2, $user->debates);
        $this->assertCount(3, $user->vocabularies);
        $this->assertContainsOnlyInstancesOf(Debate::class, $user->debates);
        $this->assertContainsOnlyInstancesOf(UserVocabulary::class, $user->vocabularies);
    }

    public function test_deleting_a_user_cascades_to_their_debates_and_vocabulary(): void
    {
        $user = User::factory()->create();
        Debate::factory()->for($user)->create();
        UserVocabulary::factory()->for($user)->create();

        $user->delete();

        $this->assertDatabaseCount('debates', 0);
        $this->assertDatabaseCount('user_vocabularies', 0);
    }
}
