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
}
