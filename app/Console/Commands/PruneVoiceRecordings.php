<?php

namespace App\Console\Commands;

use App\Enums\MessageRole;
use App\Models\DebateMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Data minimisation (GDPR): users' voice recordings are only kept for a few days.
 * Transcripts and the tutor's synthesised replies are kept.
 */
#[Signature('debates:prune-recordings')]
#[Description('Delete users\' voice recordings older than the retention period')]
class PruneVoiceRecordings extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) config('debate.audio.retention_days');
        $cutoff = now()->subDays($days);
        $disk = Storage::disk(config('debate.audio.disk'));
        $deleted = 0;

        // 1. Recordings attached to old user messages.
        DebateMessage::query()
            ->where('role', MessageRole::User)
            ->whereNotNull('audio_path')
            ->where('created_at', '<', $cutoff)
            ->chunkById(500, function ($messages) use ($disk, &$deleted) {
                foreach ($messages as $message) {
                    $deleted += (int) $disk->delete($message->audio_path);
                    $message->update(['audio_path' => null]);
                }
            });

        // 2. Orphans: recordings that never became a message (no speech, failed turns).
        foreach ($disk->directories('debates') as $debateDirectory) {
            foreach ($disk->files("{$debateDirectory}/recordings") as $file) {
                if ($disk->lastModified($file) < $cutoff->getTimestamp()) {
                    $deleted += (int) $disk->delete($file);
                }
            }
        }

        $this->components->info("Deleted {$deleted} voice recordings older than {$days} days.");

        return self::SUCCESS;
    }
}
