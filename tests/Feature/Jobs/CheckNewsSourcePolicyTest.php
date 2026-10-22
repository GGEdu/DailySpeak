<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CheckNewsSourcePolicy;
use App\Models\NewsSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CheckNewsSourcePolicyTest extends TestCase
{
    use RefreshDatabase;

    private const FEED = 'https://feeds.example.test/rss.xml';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_the_result_of_the_check_is_stored_on_the_source(): void
    {
        $source = $this->checkingSource();
        Http::fake([
            'feeds.example.test/rss.xml' => Http::response('<rss version="2.0"><channel><item><title>A</title><link>https://news.example.test/a</link></item></channel></rss>'),
            'feeds.example.test/robots.txt' => Http::response("User-agent: *\nAllow: /\n"),
            'news.example.test/robots.txt' => Http::response("User-agent: GPTBot\nDisallow: /\n"),
        ]);

        CheckNewsSourcePolicy::dispatchSync($source->id);

        $source->refresh();
        $this->assertSame('warning', $source->policy_status);
        $this->assertSame('robots.txt on news.example.test blocks GPTBot from the pages checked.', $source->policy_note);
        $this->assertNotNull($source->policy_checked_at);
    }

    public function test_a_source_deleted_while_it_waited_is_not_checked(): void
    {
        CheckNewsSourcePolicy::dispatchSync(999_999);

        Http::assertNothingSent();
    }

    public function test_a_check_that_fails_is_recorded_as_unknown_instead_of_staying_checking(): void
    {
        $source = $this->checkingSource();

        (new CheckNewsSourcePolicy($source->id))->failed(new RuntimeException('Connection lost'));

        $source->refresh();
        $this->assertSame('unknown', $source->policy_status);
        $this->assertSame('The usage terms could not be checked.', $source->policy_note);
        $this->assertNotNull($source->policy_checked_at);
    }

    public function test_a_failure_after_a_result_was_stored_keeps_the_result(): void
    {
        $source = $this->checkingSource();
        $source->forceFill(['policy_status' => 'ok', 'policy_note' => 'Nothing found.'])->save();

        (new CheckNewsSourcePolicy($source->id))->failed(new RuntimeException('Late failure'));

        $source->refresh();
        $this->assertSame('ok', $source->policy_status);
        $this->assertSame('Nothing found.', $source->policy_note);
    }

    private function checkingSource(): NewsSource
    {
        $source = NewsSource::factory()->create(['feed_url' => self::FEED]);
        $source->forceFill(['policy_status' => 'checking'])->save();

        return $source;
    }
}
