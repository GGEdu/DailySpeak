<?php

namespace App\Console\Commands;

use App\Models\NewsSource;
use App\Services\News\SourceUsagePolicy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Fills in the usage-policy result of every source, for sources added before the check existed.
 * Only downloads robots.txt files; no LLM is called.
 */
#[Signature('news:check-policies')]
#[Description('Check the usage terms of every news source (robots.txt and known publisher conditions)')]
class CheckNewsSourcePolicies extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SourceUsagePolicy $policy): int
    {
        NewsSource::query()
            ->orderBy('name')
            ->each(function (NewsSource $source) use ($policy) {
                $result = $policy->check($source);
                $line = "{$source->name}: {$result->status}. {$result->note}";

                if ($result->needsAttention()) {
                    $this->warn($line);
                } else {
                    $this->info($line);
                }
            });

        return self::SUCCESS;
    }
}
