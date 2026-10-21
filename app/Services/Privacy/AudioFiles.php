<?php

namespace App\Services\Privacy;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deletes voice files and checks that they are really gone. The local disk is configured with
 * throw=false, so a failed delete only returns false: the disk is checked instead of trusting that value.
 */
class AudioFiles
{
    public const REMOVED = 'removed';

    public const MISSING = 'missing';

    public const FAILED = 'failed';

    /**
     * Delete one file and report what happened. MISSING means the disk does not have it, so the
     * message that points at it may drop the path.
     *
     * @return self::REMOVED|self::MISSING|self::FAILED
     */
    public function removeFile(Filesystem $disk, string $path): string
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

        Log::warning('An audio file could not be deleted; it will be retried on the next run.', [
            'path' => $path,
            'error' => $error,
        ]);

        return self::FAILED;
    }

    /**
     * Delete everything stored for a debate: its recordings and the tutor's replies. Returns false when
     * something is still on the disk, so the caller can leave the debate for the next run.
     */
    public function removeDebate(Filesystem $disk, string $debateId): bool
    {
        $directory = "debates/{$debateId}";
        $error = 'files are still on the disk';

        try {
            $disk->deleteDirectory($directory);

            if ($disk->allFiles($directory) === []) {
                return true;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        Log::warning('The audio of a debate could not be deleted; it will be retried on the next run.', [
            'debate_id' => $debateId,
            'error' => $error,
        ]);

        return false;
    }
}
