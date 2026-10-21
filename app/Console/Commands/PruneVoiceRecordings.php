<?php

namespace App\Console\Commands;

use App\Enums\DebateStatus;
use App\Enums\MessageRole;
use App\Models\Debate;
use App\Models\DebateMessage;
use App\Services\Privacy\AudioFiles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Data minimisation (GDPR): users' voice recordings and the tutor's replies are only kept for a few days,
 * and finished debates are kept as long as config/privacy.php says (forever by default).
 */
#[Signature('debates:prune-recordings')]
#[Description('Delete voice recordings and tutor replies older than their retention period')]
class PruneVoiceRecordings extends Command
{
    private const CHUNK = 500;

    /**
     * Execute the console command.
     */
    public function handle(AudioFiles $audio): int
    {
        $disk = Storage::disk(config('debate.audio.disk'));
        // Never less than a day: a zero or empty setting must not delete recordings that are still being processed.
        $recordingDays = max(1, (int) config('debate.audio.retention_days'));
        $replyDays = max(1, (int) (config('privacy.replies_retention_days') ?? $recordingDays));
        $transcriptDays = (int) config('privacy.transcripts_retention_days');
        $recordingsCutoff = now()->subDays($recordingDays);
        $repliesCutoff = now()->subDays($replyDays);
        $failedPaths = [];

        // 1. Audio attached to old messages. The path is cleared only once the file is gone, so a file that
        // could not be deleted keeps its path and is retried on the next run.
        $recordings = $this->purgeMessages($disk, $audio, MessageRole::User, $recordingsCutoff, $failedPaths);
        $replies = $this->purgeMessages($disk, $audio, MessageRole::Assistant, $repliesCutoff, $failedPaths);

        // 2. Files that no message refers to: silence, failed turns, and everything left by a debate that no
        // longer exists (erased account, deleted article), whatever its age.
        $orphans = $this->purgeOrphans($disk, $audio, $recordingsCutoff, $repliesCutoff, $failedPaths);
        $recordings += $orphans['recordings'];
        $replies += $orphans['replies'];

        // 3. Finished debates past the transcript retention, with their transcripts, report and audio.
        $expiredDebates = $transcriptDays > 0
            ? $this->purgeExpiredDebates($disk, $audio, now()->subDays($transcriptDays), $failedPaths)
            : 0;

        $this->components->info("Deleted {$recordings} voice recordings older than {$recordingDays} days.");
        $this->components->info("Deleted {$replies} tutor ".Str::plural('reply', $replies)." older than {$replyDays} days.");

        if ($orphans['debates'] > 0) {
            $this->components->info("Removed the leftover audio of {$orphans['debates']} deleted ".Str::plural('debate', $orphans['debates']).'.');
        }

        if ($transcriptDays > 0) {
            $this->components->info("Deleted {$expiredDebates} ".Str::plural('debate', $expiredDebates)." ended more than {$transcriptDays} days ago.");
        }

        $failed = count($failedPaths);

        if ($failed > 0) {
            $this->components->error("{$failed} audio files could not be deleted; retrying tomorrow.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Delete the audio of messages older than the cutoff: recordings for the user's messages, replies for
     * the tutor's. Paths are cleared with one UPDATE per chunk, and only for files that are gone.
     *
     * @param  array<int, string>  $failedPaths
     */
    private function purgeMessages(Filesystem $disk, AudioFiles $audio, MessageRole $role, Carbon $cutoff, array &$failedPaths): int
    {
        $removed = 0;

        DebateMessage::query()
            ->where('role', $role)
            ->whereNotNull('audio_path')
            ->where('created_at', '<', $cutoff)
            ->chunkById(self::CHUNK, function (Collection $messages) use ($disk, $audio, &$removed, &$failedPaths) {
                $cleared = [];

                foreach ($messages as $message) {
                    $outcome = $audio->removeFile($disk, $message->audio_path);

                    if ($outcome === AudioFiles::FAILED) {
                        $failedPaths[] = $message->audio_path;

                        continue;
                    }

                    $removed += $outcome === AudioFiles::REMOVED ? 1 : 0;
                    $cleared[] = $message->id;
                }

                if ($cleared !== []) {
                    DebateMessage::query()->whereKey($cleared)->update(['audio_path' => null]);
                }
            });

        return $removed;
    }

    /**
     * Walk the debate directories. The audio of a debate that no longer exists goes whatever its age; for
     * the others, files older than the cutoff that no message refers to go.
     *
     * @param  array<int, string>  $failedPaths
     * @return array{recordings: int, replies: int, debates: int}
     */
    private function purgeOrphans(Filesystem $disk, AudioFiles $audio, Carbon $recordingsCutoff, Carbon $repliesCutoff, array &$failedPaths): array
    {
        $counts = ['recordings' => 0, 'replies' => 0, 'debates' => 0];

        $debateIds = collect($disk->directories('debates'))
            ->map(fn (string $directory) => basename($directory))
            ->filter(fn (string $id) => ctype_digit($id))
            ->values();

        foreach ($debateIds->chunk(self::CHUNK) as $chunk) {
            $existing = Debate::query()->whereKey($chunk->all())->pluck('id')->map(fn ($id) => (string) $id)->all();

            foreach ($chunk as $debateId) {
                if (! in_array($debateId, $existing, true)) {
                    if ($audio->removeDebate($disk, $debateId)) {
                        $counts['debates']++;
                    } else {
                        $failedPaths[] = "debates/{$debateId}";
                    }

                    continue;
                }

                $counts['recordings'] += $this->purgeOrphanFiles($disk, $audio, 'recordings', $debateId, $recordingsCutoff, $failedPaths);
                $counts['replies'] += $this->purgeOrphanFiles($disk, $audio, 'replies', $debateId, $repliesCutoff, $failedPaths);
            }
        }

        return $counts;
    }

    /**
     * Delete the files of one debate directory that are older than the cutoff and that no message refers to
     * (step 1 already handled the ones that do). Files step 1 failed on are still on the disk, so they are
     * skipped here, not counted twice.
     *
     * @param  array<int, string>  $failedPaths
     */
    private function purgeOrphanFiles(Filesystem $disk, AudioFiles $audio, string $kind, string $debateId, Carbon $cutoff, array &$failedPaths): int
    {
        $removed = 0;

        foreach ($disk->files("debates/{$debateId}/{$kind}") as $file) {
            if (in_array($file, $failedPaths, true) || $disk->lastModified($file) >= $cutoff->getTimestamp()) {
                continue;
            }

            $outcome = $audio->removeFile($disk, $file);

            if ($outcome === AudioFiles::FAILED) {
                $failedPaths[] = $file;
            } else {
                $removed += $outcome === AudioFiles::REMOVED ? 1 : 0;
            }
        }

        return $removed;
    }

    /**
     * Delete finished debates that ended before the cutoff, with their audio, then the debates themselves
     * (their messages and reports go with them). A debate whose audio could not be deleted is kept and
     * retried on the next run.
     *
     * @param  array<int, string>  $failedPaths
     */
    private function purgeExpiredDebates(Filesystem $disk, AudioFiles $audio, Carbon $cutoff, array &$failedPaths): int
    {
        $deleted = 0;

        Debate::query()
            ->where('status', DebateStatus::Completed)
            ->where('ended_at', '<', $cutoff)
            ->chunkById(self::CHUNK, function (Collection $debates) use ($disk, $audio, &$deleted, &$failedPaths) {
                $done = [];

                foreach ($debates as $debate) {
                    if ($audio->removeDebate($disk, (string) $debate->id)) {
                        $done[] = $debate->id;
                    } else {
                        $failedPaths[] = "debates/{$debate->id}";
                    }
                }

                if ($done !== []) {
                    $deleted += Debate::query()->whereKey($done)->delete();
                }
            });

        return $deleted;
    }
}
