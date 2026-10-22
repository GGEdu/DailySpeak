<?php

namespace App\Services\Privacy;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
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
                // exists() is false too when the file cannot be reached; only a reachable place is really empty.
                $blocked = $this->unreachable($disk, $path);

                if ($blocked === null) {
                    return self::MISSING;
                }

                throw new \RuntimeException($blocked);
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
     * Why a path the disk says is not there may still be on it: the disk root is missing (a volume that is not
     * mounted) or a directory on the way cannot be read. Null when the absence is real. Only local disks are
     * checked; for the others exists() is taken at its word.
     */
    private function unreachable(Filesystem $disk, string $path): ?string
    {
        if (! $disk instanceof FilesystemAdapter || ($disk->getConfig()['driver'] ?? null) !== 'local') {
            return null;
        }

        $root = rtrim($disk->path(''), '/');

        if (! is_dir($root)) {
            return 'the disk root is not there (is the volume mounted?)';
        }

        // Walk up from the file's directory to the first one that exists (a directory that cannot be read hides
        // everything under it): that one has to be readable for the absence to be real.
        $directory = dirname($disk->path($path));

        while (strlen($directory) > strlen($root) && ! file_exists($directory)) {
            $directory = dirname($directory);
        }

        return is_readable($directory) && is_executable($directory) ? null : "{$directory} cannot be read";
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
