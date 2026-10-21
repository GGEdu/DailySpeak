<?php

namespace Tests\Feature\Web;

use App\Models\Debate;
use App\Models\DebateMessage;
use App\Models\User;
use App\Models\UserVocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_the_account_removes_its_rows_and_its_audio_files(): void
    {
        $disk = Storage::fake('local');
        $user = User::factory()->create();
        $debate = Debate::factory()->for($user)->completed()->create();
        $recording = DebateMessage::factory()->for($debate)->fromUser()->create([
            'audio_path' => "debates/{$debate->id}/recordings/turn.webm",
        ]);
        $reply = DebateMessage::factory()->for($debate)->fromAssistant()->create([
            'audio_path' => "debates/{$debate->id}/replies/answer.mp3",
        ]);
        $disk->put($recording->audio_path, 'user voice');
        $disk->put($reply->audio_path, 'tutor voice');
        $vocabulary = UserVocabulary::factory()->for($user)->create();
        $article = $debate->newsArticle;
        $user->createToken('phone');
        $sessionId = Str::random(40);
        DB::table('sessions')->insert(['id' => $sessionId, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($user)
            ->from('/settings')
            ->delete('/settings/account', ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('debates', ['id' => $debate->id]);
        $this->assertDatabaseMissing('debate_messages', ['debate_id' => $debate->id]);
        $this->assertDatabaseMissing('user_vocabularies', ['id' => $vocabulary->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $disk->assertMissing($recording->audio_path);
        $disk->assertMissing($reply->audio_path);
        $this->assertSame([], $disk->allFiles("debates/{$debate->id}"));

        // News articles are shared by every reader: they stay.
        $this->assertDatabaseHas('news_articles', ['id' => $article->id]);
        $this->assertGuest();
    }

    public function test_other_users_and_their_audio_are_not_touched(): void
    {
        $disk = Storage::fake('local');
        $other = Debate::factory()->create();
        $disk->put("debates/{$other->id}/recordings/theirs.webm", 'their voice');

        $this->actingAs(User::factory()->create())
            ->delete('/settings/account', ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertDatabaseHas('debates', ['id' => $other->id]);
        $disk->assertExists("debates/{$other->id}/recordings/theirs.webm");
    }

    public function test_a_wrong_password_deletes_nothing(): void
    {
        $disk = Storage::fake('local');
        $user = User::factory()->create();
        $debate = Debate::factory()->for($user)->create();
        $disk->put("debates/{$debate->id}/recordings/turn.webm", 'voice');

        $this->actingAs($user)
            ->from('/settings')
            ->delete('/settings/account', ['password' => 'not-the-password'])
            ->assertSessionHasErrors('password')
            ->assertRedirect('/settings');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertDatabaseHas('debates', ['id' => $debate->id]);
        $disk->assertExists("debates/{$debate->id}/recordings/turn.webm");
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_password_is_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/settings')
            ->delete('/settings/account', [])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_a_remembered_login_does_not_bring_the_account_back(): void
    {
        // Logging out saves the user to rotate its remember token. That save must happen before the
        // row is deleted, or Eloquent inserts the user again.
        $user = User::factory()->create();
        $user->setRememberToken(Str::random(60));
        $user->save();

        $this->actingAs($user)
            ->delete('/settings/account', ['password' => 'password'])
            ->assertRedirect('/');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->delete('/settings/account', ['password' => 'password'])->assertRedirect('/login');
    }
}
