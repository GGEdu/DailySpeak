<?php

namespace App\Services\Debate;

use App\Models\Debate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Audio;
use Laravel\Ai\Responses\AudioResponse;
use RuntimeException;
use Throwable;

/**
 * Speaks the tutor's words: the main voice, then the backup voice when the main one fails (config/debate.php, tts).
 * Used by every tutor message that is spoken: the answer to a voice turn and the opening of a guided debate.
 */
class TutorVoice
{
    /**
     * Synthesise the text to an MP3 file in the debate's replies folder.
     *
     * @param  array<string, mixed>  $logContext  Fields for the log lines about this synthesis (such as the turn).
     * @return array{0: string, 1: bool} The storage path, and whether the backup voice made it.
     *
     * @throws Throwable when no voice could speak the text (the main one failed and there is no backup voice, or the backup failed too)
     */
    public function synthesise(Debate $debate, string $text, array $logContext = []): array
    {
        $backupVoice = false;

        try {
            $audio = $this->speak($text, config('debate.tts'));
        } catch (Throwable $e) {
            if (config('debate.tts.fallback.model') === null) {
                throw $e;
            }

            // The user still hears the reply, in the backup voice; the failure stays in the log.
            Log::warning('Main voice failed; answering with the backup voice.', [
                'debate_id' => $debate->getKey(),
                ...$logContext,
                'tts' => config('debate.tts.provider'),
                'exception' => $e::class,
            ]);
            report($e);

            $audio = $this->speak($text, config('debate.tts.fallback'));
            $backupVoice = true;
        }

        $path = $audio->storeAs("debates/{$debate->id}/replies", Str::uuid().'.mp3', config('debate.audio.disk'));

        if (! is_string($path)) {
            throw new RuntimeException('The synthesised reply could not be stored.');
        }

        return [$path, $backupVoice];
    }

    /**
     * @param  array{provider: string, model: ?string, voice: string, timeout: int}  $tts
     */
    private function speak(string $text, array $tts): AudioResponse
    {
        return Audio::of($text)
            ->voice($tts['voice'])
            ->timeout($tts['timeout'])
            ->generate($tts['provider'], $tts['model']);
    }
}
