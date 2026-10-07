<?php

namespace App\Console\Commands;

use App\Enums\MessageRole;
use App\Models\DebateMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Data minimisation (GDPR): users' voice recordings are only kept for a few days.
 * Transcripts and the tutor's synthesised replies are kept.
 */
#[Signature('debates:prune-recordings')]
#[Description('Delete users\' voice recordings older than the retention period')]
class PruneVoiceRecordings extends Command
{
    private const REMOVED = 'removed';

    private const MISSING = 'missing';

    private const FAILED = 'failed';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) config('debate.audio.retention_days');
        $cutoff = now()->subDays($days);
        $disk = Storage::disk(config('debate.audio.disk'));
        $removed = 0;
        $failedPaths = [];

        // 1. Recordings attached to old user messages. The path is cleared only once the file is gone,
        // so a recording that could not be deleted is retried on the next run.
        DebateMessage::query()
            ->where('role', MessageRole::User)
            ->whereNotNull('audio_path')
            ->where('created_at', '<', $cutoff)
            ->chunkById(500, function ($messages) use ($disk, &$removed, &$failedPaths) {
                foreach ($messages as $message) {
                    $outcome = $this->remove($disk, $message->audio_path);

                    if ($outcome === self::FAILED) {
                        $failedPaths[] = $message->audio_path;

                        continue;
                    }

                    $removed += $outcome === self::REMOVED ? 1 : 0;
                    $message->update(['audio_path' => null]);
                }
            });

        // 2. Orphans: recordings that never became a message (no speech, failed turns). Files that
        // step 1 already failed on are still on the disk, so they are skipped here, not counted twice.
        foreach ($disk->directories('debates') as $debateDirectory) {
            foreach ($disk->files("{$debateDirectory}/recordings") as $file) {
                if (in_array($file, $failedPaths, true) || $disk->lastModified($file) >= $cutoff->getTimestamp()) {
                    continue;
                }

                $outcome = $this->remove($disk, $file);

                if ($outcome === self::FAILED) {
                    $failedPaths[] = $file;
                } else {
                    $removed += $outcome === self::REMOVED ? 1 : 0;
                }
            }
        }

        $failed = count($failedPaths);
        $this->components->info("Deleted {$removed} voice recordings older than {$days} days.");

        if ($failed > 0) {
            $this->components->error("{$failed} voice recordings could not be deleted; retrying tomorrow.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Delete one recording and report whether it is gone afterwards. The local disk is configured with
     * throw=false, so a failed delete only returns false: the disk is checked instead of trusting that value.
     *
     * @return self::REMOVED|self::MISSING|self::FAILED
     */
    private function remove(Filesystem $disk, string $path): string
    {
        $error = 'the file is still on the disk';

        try {
            if (! $disk->exists($path)) {
                return self::MISSING;
            }

            $disk->delete($path);

            if (! $disk->exists($path)) {
                return self::REMOVED;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        Log::warning('A voice recording could not be deleted; it will be retried on the next run.', [
            'path' => $path,
            'error' => $error,
        ]);

        return self::FAILED;
    }
}
