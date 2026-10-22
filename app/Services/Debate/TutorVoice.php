<?php

namespace App\Services\Debate;

use App\Models\Debate;
use App\Services\Ai\AiCalls;
use App\Services\Ai\AiConnection;
use Illuminate\Support\Str;
use Laravel\Ai\Audio;
use RuntimeException;

/**
 * Speaks the tutor's words with the debate owner's own voice connection (tts). Used by every tutor message that is
 * spoken: the answer to a voice turn and the opening of a guided debate. There is no second voice to fall back on: a
 * failed synthesis is the caller's to report, and the text is still shown.
 */
class TutorVoice
{
    public function __construct(private AiCalls $calls) {}

    /**
     * Synthesise the text to an MP3 file in the debate's replies folder.
     *
     * @return string The storage path.
     *
     * @throws \Throwable when the owner has no voice connection, or the provider fails to speak the text
     */
    public function synthesise(Debate $debate, string $text): string
    {
        $audio = $this->calls->forUser(
            $debate->user,
            'tts',
            fn (AiConnection $connection) => Audio::of($text)
                ->voice((string) $connection->voice)
                ->timeout(config('debate.tts.timeout'))
                ->generate($connection->providerName(), $connection->model),
            fn () => AiCalls::characters($text),
        );

        $path = $audio->storeAs("debates/{$debate->id}/replies", Str::uuid().'.mp3', config('debate.audio.disk'));

        if (! is_string($path)) {
            throw new RuntimeException('The synthesised reply could not be stored.');
        }

        return $path;
    }
}
