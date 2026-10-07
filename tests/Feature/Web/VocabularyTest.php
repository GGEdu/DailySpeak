<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Models\UserVocabulary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class VocabularyTest extends TestCase
{
    use RefreshDatabase;

    public function test_remembered_words_move_up_a_box_and_come_back_later(): void
    {
        $this->freezeSecond();
        $word = UserVocabulary::factory()->create(['mastery_level' => 1]);

        foreach ([2 => 2, 3 => 4, 4 => 8, 5 => 16, 6 => 16] as $review => $days) {
            $word->review(remembered: true);

            $this->assertSame(min($review, UserVocabulary::MAX_MASTERY), $word->mastery_level);
            $this->assertTrue($word->next_review_at->equalTo(now()->addDays($days)));
        }
    }

    public function test_forgotten_words_go_back_to_the_first_box_and_return_soon(): void
    {
        $this->freezeSecond();
        $word = UserVocabulary::factory()->create(['mastery_level' => 4]);

        $word->review(remembered: false);

        $this->assertSame(UserVocabulary::MIN_MASTERY, $word->fresh()->mastery_level);
        $this->assertTrue($word->fresh()->next_review_at->equalTo(now()->addMinutes(UserVocabulary::RELEARN_MINUTES)));
    }

    public function test_the_page_lists_due_words_first_and_every_word_alphabetically(): void
    {
        $user = User::factory()->create();
        $later = UserVocabulary::factory()->for($user)->create(['word' => 'ubiquitous', 'next_review_at' => now()->addDays(3)]);
        $overdue = UserVocabulary::factory()->for($user)->create(['word' => 'mitigate', 'next_review_at' => now()->subDays(2), 'context' => 'It could mitigate congestion.']);
        $dueNow = UserVocabulary::factory()->for($user)->create(['word' => 'contentious', 'next_review_at' => now()->subMinute()]);
        UserVocabulary::factory()->create(['word' => 'someone-elses']);

        $this->actingAs($user)
            ->get('/vocabulary')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vocabulary')
                ->where('maxLevel', 5)
                ->where('due.0.id', $overdue->id)
                ->where('due.0.context', 'It could mitigate congestion.')
                ->where('due.1.id', $dueNow->id)
                ->has('due', 2)
                ->where('words.0.word', 'contentious')
                ->where('words.1.word', 'mitigate')
                ->where('words.2.word', 'ubiquitous')
                ->has('words', 3)
                ->where('dueWords', 2));
    }

    public function test_reviewing_a_word_records_the_answer(): void
    {
        $word = UserVocabulary::factory()->create(['mastery_level' => 2, 'next_review_at' => now()->subMinute()]);

        $this->actingAs($word->user)
            ->from('/vocabulary')
            ->post("/vocabulary/{$word->id}/review", ['remembered' => true])
            ->assertRedirect('/vocabulary');

        $this->assertSame(3, $word->fresh()->mastery_level);
        $this->actingAs($word->user)->post("/vocabulary/{$word->id}/review", [])->assertSessionHasErrors('remembered');
    }

    public function test_words_can_be_removed(): void
    {
        $word = UserVocabulary::factory()->create();

        $this->actingAs($word->user)->delete("/vocabulary/{$word->id}")->assertRedirect();

        $this->assertModelMissing($word);
    }

    public function test_users_cannot_touch_someone_elses_words(): void
    {
        $word = UserVocabulary::factory()->create(['mastery_level' => 2]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post("/vocabulary/{$word->id}/review", ['remembered' => true])->assertForbidden();
        $this->actingAs($intruder)->delete("/vocabulary/{$word->id}")->assertForbidden();

        $this->assertSame(2, $word->fresh()->mastery_level);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/vocabulary')->assertRedirect('/login');
    }
}
