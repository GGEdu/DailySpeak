<?php

namespace App\Jobs;

use App\Models\NewsSource;
use App\Services\News\SourcePolicyResult;
use App\Services\News\SourceUsagePolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Checks a source's usage terms (robots.txt of its hosts) after it was saved or re-checked by an admin.
 *
 * Reading up to four robots.txt files, each up to 20 s, and the feed, with its retries, can take minutes. That
 * must not happen inside the admin's request, where a proxy cuts it off with a 504 after the source was saved.
 * The timeout is above that worst case and below the redis connection's retry_after (180 s), so a slow check is
 * stopped here, not run twice.
 */
class CheckNewsSourcePolicy implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $timeout = 150;

    public int $tries = 1;

    public function __construct(public int $sourceId)
    {
        $this->onQueue('default');
    }

    public function handle(SourceUsagePolicy $policy): void
    {
        $source = NewsSource::find($this->sourceId);

        // The source may have been deleted while the job waited.
        if ($source === null) {
            return;
        }

        $policy->check($source);
    }

    /**
     * Without this the source would show "Checking terms…" for ever.
     */
    public function failed(Throwable $exception): void
    {
        report($exception);

        NewsSource::whereKey($this->sourceId)
            ->where('policy_status', SourcePolicyResult::CHECKING)
            ->update([
                'policy_status' => SourcePolicyResult::UNKNOWN,
                'policy_note' => 'The usage terms could not be checked.',
                'policy_checked_at' => now(),
            ]);
    }
}
