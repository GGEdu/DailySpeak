<?php

namespace Tests\Feature\Web;

use App\Enums\EnglishLevel;
use App\Models\Debate;
use App\Models\DebateMessage;
use App\Models\User;
use App\Models\UserVocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_export_holds_the_profile_debates_transcripts_reports_and_vocabulary(): void
    {
        $user = User::factory()->level(EnglishLevel::C1)->create(['name' => 'Ana']);
        $debate = Debate::factory()->for($user)->completed()->create();
        DebateMessage::factory()->for($debate)->fromUser()->create(['transcript' => 'I agree with the policy.']);
        DebateMessage::factory()->for($debate)->fromAssistant()->create(['transcript' => 'Why do you agree?']);
        UserVocabulary::factory()->for($user)->create([
            'word' => 'contentious',
            'context' => 'a contentious policy',
            'translation' => 'polémico',
        ]);

        $response = $this->actingAs($user)->get('/settings/export')->assertOk();

        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
        $data = $response->json();

        $this->assertSame('Ana', $data['profile']['name']);
        $this->assertSame($user->email, $data['profile']['email']);
        $this->assertSame('C1', $data['profile']['current_level']);

        $this->assertCount(1, $data['debates']);
        $this->assertSame('completed', $data['debates'][0]['status']);
        $this->assertSame($debate->newsArticle->title, $data['debates'][0]['news_article']['title']);
        $this->assertSame(
            [['role' => 'user', 'transcript' => 'I agree with the policy.'], ['role' => 'assistant', 'transcript' => 'Why do you agree?']],
            array_map(fn (array $message) => ['role' => $message['role'], 'transcript' => $message['transcript']], $data['debates'][0]['messages']),
        );
        $this->assertSame(['very', 'thing'], $data['debates'][0]['ai_feedback']['crutch_words']);

        $this->assertCount(1, $data['vocabulary']);
        $this->assertSame('contentious', $data['vocabulary'][0]['word']);
        $this->assertSame('polémico', $data['vocabulary'][0]['translation']);
    }

    public function test_the_export_leaves_out_audio_credentials_and_tokens(): void
    {
        $user = User::factory()->create();
        $debate = Debate::factory()->for($user)->create();
        DebateMessage::factory()->for($debate)->fromUser()->create([
            'audio_path' => "debates/{$debate->id}/recordings/secret-turn.webm",
        ]);
        $token = $user->createToken('phone');

        $json = $this->actingAs($user)->get('/settings/export')->assertOk()->getContent();

        $this->assertStringNotContainsString('audio_path', $json);
        $this->assertStringNotContainsString('secret-turn', $json);
        $this->assertStringNotContainsString($user->password, $json);
        $this->assertStringNotContainsString('remember_token', $json);
        $this->assertStringNotContainsString($token->accessToken->token, $json);
        $this->assertStringNotContainsString($token->plainTextToken, $json);
    }

    public function test_an_unverified_user_can_download_their_data(): void
    {
        // The right to a copy does not wait for a confirmed address.
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get('/settings/export')->assertOk();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/settings/export')->assertRedirect('/login');
    }
}
