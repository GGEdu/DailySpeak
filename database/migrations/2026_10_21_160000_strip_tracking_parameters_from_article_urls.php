<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The feed reader now drops analytics parameters (utm_*, at_*, traffic_source, fbclid) and the #fragment
 * from article links. Articles saved before carry them, so the next harvest would not recognise them and
 * would summarise and list them a second time. Their links are cleaned the same way here. A link whose clean
 * form is already taken by another article is left as it is: both rows already exist.
 *
 * The rule is copied from RssFeedReader on purpose: a migration must keep doing what it did when it ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('news_articles')
            ->where(fn ($query) => $query->where('source_url', 'like', '%?%')->orWhere('source_url', 'like', '%#%'))
            ->select(['id', 'source_url'])
            // By id, not by offset: a cleaned row leaves the filter, and offset pages would then skip rows.
            ->lazyById(100)
            ->each(function (object $article) {
                $clean = $this->withoutTrackingParameters($article->source_url);

                if ($clean === $article->source_url || DB::table('news_articles')->where('source_url', $clean)->exists()) {
                    return;
                }

                DB::table('news_articles')->where('id', $article->id)->update(['source_url' => $clean]);
            });
    }

    public function down(): void
    {
        // The removed parameters are not kept anywhere, and the clean links identify the same articles.
    }

    private function withoutTrackingParameters(string $url): string
    {
        $url = Str::before($url, '#');

        if (! str_contains($url, '?')) {
            return $url;
        }

        [$base, $query] = explode('?', $url, 2);

        $kept = array_values(array_filter(
            explode('&', $query),
            fn (string $pair) => $pair !== '' && ! $this->isTrackingParameter(Str::before($pair, '=')),
        ));

        return $kept === [] ? $base : $base.'?'.implode('&', $kept);
    }

    private function isTrackingParameter(string $name): bool
    {
        return preg_match('/^(utm_|at_)/i', $name) === 1
            || in_array(strtolower($name), ['traffic_source', 'fbclid'], true);
    }
};
