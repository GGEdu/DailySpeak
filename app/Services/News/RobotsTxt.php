<?php

namespace App\Services\News;

/**
 * The parts of a robots.txt file that matter for usage policy: its rule groups and its comments.
 *
 * Follows RFC 9309: the group that names a crawler replaces the `*` group for that crawler, the
 * longest matching path wins, and an Allow beats a Disallow of the same length.
 */
final class RobotsTxt
{
    private const DIRECTIVES = ['user-agent', 'allow', 'disallow', 'sitemap', 'crawl-delay'];

    /**
     * Rules the file may hold in all. Groups that name the same crawler are merged, so a per-group limit
     * alone would not bound the work done for one crawler.
     */
    private const MAX_RULES = 2000;

    /** Wildcards a single pattern may have; more than this and the file is not complete. */
    private const MAX_WILDCARDS = 16;

    /** @var list<array{agents: list<string>, rules: list<array{allow: bool, pattern: string}>}> */
    private array $groups = [];

    /** @var list<string> */
    private array $comments = [];

    private bool $hasContent = false;

    private bool $hasDirectives = false;

    private int $ruleCount = 0;

    /** False when a rule was dropped for the limits above, so the file cannot be trusted to say everything. */
    private bool $complete = true;

    private function __construct() {}

    public static function parse(string $body): self
    {
        $robots = new self;
        $body = str_starts_with($body, "\xEF\xBB\xBF") ? substr($body, 3) : $body;
        $inAgentRun = false;

        foreach (preg_split('/\R/', $body) as $line) {
            [$content, $comment] = array_pad(explode('#', $line, 2), 2, null);

            if ($comment !== null) {
                $robots->comments[] = trim($comment);
            }

            if (! str_contains($content, ':')) {
                $robots->hasContent = $robots->hasContent || trim($content) !== '';

                continue;
            }

            [$name, $value] = array_map('trim', explode(':', $content, 2));
            $name = strtolower($name);
            $robots->hasContent = true;

            if (! in_array($name, self::DIRECTIVES, true)) {
                $inAgentRun = false;

                continue;
            }

            $robots->hasDirectives = true;

            // Consecutive User-agent lines share one group; a rule line closes the run.
            if ($name === 'user-agent') {
                if (! $inAgentRun) {
                    $robots->groups[] = ['agents' => [], 'rules' => []];
                }

                $last = array_key_last($robots->groups);
                $robots->groups[$last]['agents'][] = strtolower($value);
                $inAgentRun = true;

                continue;
            }

            $inAgentRun = false;

            if (in_array($name, ['allow', 'disallow'], true) && $robots->groups !== []) {
                $robots->addRule($name === 'allow', $value);
            }
        }

        return $robots;
    }

    /**
     * An empty file, which is what a missing robots.txt means: nothing is restricted.
     */
    public static function empty(): self
    {
        return self::parse('');
    }

    /**
     * False for a body with text in it but no robots directive, such as an HTML error page served with 200.
     */
    public function isRobotsFile(): bool
    {
        return ! $this->hasContent || $this->hasDirectives;
    }

    /**
     * False when the file has more rules, or patterns with more wildcards, than this class checks. Such a file
     * may hold a Disallow that is not evaluated, so its answers must not be taken as the whole picture.
     */
    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * @return list<string> every comment, without its `#`
     */
    public function comments(): array
    {
        return $this->comments;
    }

    /**
     * Whether a group of the file names this crawler, rather than leaving it to `*`.
     */
    public function namesAgent(string $agent): bool
    {
        foreach ($this->groups as $group) {
            if (in_array(strtolower($agent), $group['agents'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the crawler may fetch the path (path and query, as in the URL).
     */
    public function allows(string $agent, string $path): bool
    {
        $deciding = null;

        foreach ($this->rulesFor($agent) as $rule) {
            if ($rule['pattern'] === '' || ! self::matches($rule['pattern'], $path)) {
                continue;
            }

            $length = strlen($rule['pattern']);

            if ($deciding === null || $length > $deciding['length'] || ($length === $deciding['length'] && $rule['allow'])) {
                $deciding = ['length' => $length, 'allow' => $rule['allow']];
            }
        }

        return $deciding === null || $deciding['allow'];
    }

    /**
     * The rules of every group that names the crawler, or of the `*` groups when none does.
     *
     * @return list<array{allow: bool, pattern: string}>
     */
    private function rulesFor(string $agent): array
    {
        $agent = strtolower($agent);
        $named = array_filter($this->groups, fn (array $group) => in_array($agent, $group['agents'], true));

        if ($named === []) {
            $named = array_filter($this->groups, fn (array $group) => in_array('*', $group['agents'], true));
        }

        return array_merge([], ...array_column($named, 'rules'));
    }

    private function addRule(bool $allow, string $pattern): void
    {
        if ($this->ruleCount >= self::MAX_RULES || substr_count($pattern, '*') > self::MAX_WILDCARDS) {
            $this->complete = false;

            return;
        }

        $this->ruleCount++;
        $last = array_key_last($this->groups);
        $this->groups[$last]['rules'][] = ['allow' => $allow, 'pattern' => $pattern];
    }

    /**
     * A pattern matches from the start of the path; `*` matches any run of characters and a trailing `$` anchors the end.
     *
     * Matched without a regular expression: each literal part is looked for once, from the place the previous
     * one ended, so the work is linear in the length of the path. A backtracking regex takes exponential time on
     * patterns such as `/*a*a*a*c` and gives up, and a gave-up match counts as no match.
     */
    private static function matches(string $pattern, string $path): bool
    {
        $anchored = str_ends_with($pattern, '$');
        $parts = explode('*', $anchored ? substr($pattern, 0, -1) : $pattern);
        $prefix = array_shift($parts);

        if (! str_starts_with($path, $prefix)) {
            return false;
        }

        if ($parts === []) {
            return ! $anchored || $path === $prefix;
        }

        $position = strlen($prefix);
        $end = strlen($path);

        if ($anchored) {
            $suffix = array_pop($parts);

            // The suffix must be the end of the path and must not overlap the prefix.
            if (! str_ends_with($path, $suffix) || $end - strlen($suffix) < $position) {
                return false;
            }

            $end -= strlen($suffix);
        }

        foreach ($parts as $part) {
            $found = strpos($path, $part, $position);

            if ($found === false || $found + strlen($part) > $end) {
                return false;
            }

            $position = $found + strlen($part);
        }

        return true;
    }
}
