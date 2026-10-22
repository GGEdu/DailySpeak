<?php

namespace Tests\Unit\News;

use App\Services\News\RobotsTxt;
use PHPUnit\Framework\TestCase;

class RobotsTxtTest extends TestCase
{
    public function test_the_group_that_names_a_crawler_replaces_the_wildcard_group(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /\n\nUser-agent: GPTBot\nAllow: /\n");

        $this->assertTrue($robots->allows('GPTBot', '/news/articles/a'));
        $this->assertFalse($robots->allows('ClaudeBot', '/news/articles/a'));
        $this->assertTrue($robots->namesAgent('gptbot'));
        $this->assertFalse($robots->namesAgent('ClaudeBot'));
    }

    public function test_consecutive_user_agent_lines_share_one_group(): void
    {
        $robots = RobotsTxt::parse("User-agent: GPTBot\nUser-agent: ClaudeBot\nDisallow: /articles\n");

        $this->assertFalse($robots->allows('GPTBot', '/articles/1'));
        $this->assertFalse($robots->allows('ClaudeBot', '/articles/1'));
        $this->assertTrue($robots->allows('GPTBot', '/about'));
    }

    public function test_the_longest_matching_rule_wins(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /\nAllow: /news/\n");

        $this->assertTrue($robots->allows('GPTBot', '/news/world'));
        $this->assertFalse($robots->allows('GPTBot', '/sport'));
    }

    public function test_an_allow_beats_a_disallow_of_the_same_length(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /a\nAllow: /a\n");

        $this->assertTrue($robots->allows('GPTBot', '/a/b'));
    }

    public function test_wildcards_and_the_end_anchor_match_as_in_rfc_9309(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /*.pdf$\nDisallow: /private*/\n");

        $this->assertFalse($robots->allows('GPTBot', '/files/report.pdf'));
        $this->assertTrue($robots->allows('GPTBot', '/files/report.pdf?download=1'));
        $this->assertFalse($robots->allows('GPTBot', '/private-area/'));
        $this->assertTrue($robots->allows('GPTBot', '/news/report.html'));
    }

    public function test_an_empty_disallow_allows_everything(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow:\n");

        $this->assertTrue($robots->allows('GPTBot', '/anything'));
    }

    public function test_it_reads_bom_crlf_and_comments(): void
    {
        $robots = RobotsTxt::parse("\xEF\xBB\xBF# Do not use our text to train LLMs.\r\nuser-agent: GPTBot # the training crawler\r\nDisallow: /\r\n");

        $this->assertFalse($robots->allows('GPTBot', '/'));
        $this->assertSame(['Do not use our text to train LLMs.', 'the training crawler'], $robots->comments());
        $this->assertTrue($robots->isRobotsFile());
    }

    public function test_an_html_page_is_not_a_robots_file(): void
    {
        $this->assertFalse(RobotsTxt::parse('<html><body>Service unavailable</body></html>')->isRobotsFile());
        $this->assertTrue(RobotsTxt::parse('')->isRobotsFile());
        $this->assertTrue(RobotsTxt::parse("# only a comment\n")->isRobotsFile());
    }

    public function test_rules_before_any_user_agent_line_are_ignored(): void
    {
        $robots = RobotsTxt::parse("Disallow: /\nUser-agent: GPTBot\nAllow: /\n");

        $this->assertTrue($robots->allows('GPTBot', '/'));
        $this->assertTrue($robots->allows('ClaudeBot', '/'));
    }

    public function test_wildcard_and_end_anchor_patterns_match_exactly_as_before(): void
    {
        $cases = [
            ['/a*', '/abc', true],
            ['/a*', '/', false],
            ['/a*b', '/axxbc', true],
            ['/a*b$', '/axxbc', false],
            ['/a*b$', '/axxb', true],
            ['/*.pdf$', '/files/report.pdf', true],
            ['/*.pdf$', '/files/report.pdf?x=1', false],
            ['/a$', '/a', true],
            ['/a$', '/ab', false],
            ['/a*a$', '/a', false],
            ['/a*a$', '/aa', true],
            ['/ab*ba$', '/aba', false],
            ['/a*b*c$', '/abc', true],
            ['/a*b*c$', '/acb', false],
            ['/a.b', '/axb', false],
            ['/A', '/a', false],
            ['/a$b', '/a$b', true],
            ['*', '/anything', true],
        ];

        foreach ($cases as [$pattern, $path, $matches]) {
            $robots = RobotsTxt::parse("User-agent: *\nDisallow: {$pattern}\n");

            $this->assertSame(! $matches, $robots->allows('GPTBot', $path), "Disallow: {$pattern} against {$path}");
        }
    }

    public function test_a_pathological_wildcard_pattern_is_decided_quickly(): void
    {
        // The path has a c, so the regular expression runs, and it cannot find fifteen a's before it: it tries
        // every way of splitting the a's among the stars until its backtrack limit gives up (about 3 ms a rule).
        $pattern = '/'.str_repeat('*a', 15).'*c';
        $robots = RobotsTxt::parse("User-agent: *\n".str_repeat("Disallow: {$pattern}\n", 1000));

        $started = hrtime(true);
        $allowed = $robots->allows('GPTBot', '/c'.str_repeat('a', 60));
        $seconds = (hrtime(true) - $started) / 1e9;

        $this->assertTrue($allowed);
        $this->assertLessThan(1.0, $seconds);
        $this->assertFalse($robots->allows('GPTBot', '/'.str_repeat('a', 15).'c'));
    }

    public function test_a_file_with_more_rules_than_it_can_check_is_not_complete(): void
    {
        $this->assertTrue(RobotsTxt::parse("User-agent: *\n".str_repeat("Disallow: /private\n", 2000))->isComplete());
        $this->assertFalse(RobotsTxt::parse("User-agent: *\n".str_repeat("Disallow: /private\n", 2001))->isComplete());
    }

    public function test_a_pattern_with_too_many_wildcards_is_not_complete(): void
    {
        $this->assertTrue(RobotsTxt::parse("User-agent: *\nDisallow: /".str_repeat('*a', 16)."\n")->isComplete());
        $this->assertFalse(RobotsTxt::parse("User-agent: *\nDisallow: /".str_repeat('*a', 17)."\n")->isComplete());
    }
}
