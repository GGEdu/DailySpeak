<?php

namespace App\Http\Controllers;

use App\Enums\MessageRole;
use App\Http\Requests\StoreDebateAudioRequest;
use App\Jobs\ProcessVoiceDebate;
use App\Models\Debate;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DebateAudioController extends Controller
{
    /**
     * Receive a recorded voice turn and queue the tutor's answer.
     *
     * The reply is pushed to the debate's private channel (AIResponseGenerated). The 202 names the turn with the
     * recording's uuid, which the turn's events carry back, so the client can tell which turn each one answers.
     *
     * The client may name the turn itself (a UUID in "turn"). A re-sent upload of the same turn, such as a retry
     * after a timed-out POST that did reach the server, is then answered with the same 202 and is not stored or
     * queued again: a second copy would be a second paid turn.
     */
    public function store(StoreDebateAudioRequest $request, Debate $debate): JsonResponse
    {
        $recording = $request->file('audio');
        $extension = config('debate.audio.formats')[$recording->guessExtension()];

        if (! $request->filled('turn')) {
            return $this->queueTurn($debate, $recording, $extension, (string) Str::uuid());
        }

        $turn = strtolower((string) $request->string('turn'));

        // The lock makes "is it already here?" and "store it" one step, so two copies that arrive together
        // cannot both pass the check.
        try {
            return Cache::lock($this->lockKey($debate, $turn), 60)->block(15, function () use ($debate, $recording, $extension, $turn) {
                if ($this->alreadyReceived($debate, $turn)) {
                    return $this->accepted($turn);
                }

                return $this->queueTurn($debate, $recording, $extension, $turn);
            });
        } catch (LockTimeoutException) {
            // The first copy is still being received: the client tries again, and then gets the 202.
            return response()->json(['message' => 'This turn is still being received. Try again in a moment.'], 409);
        }
    }

    /**
     * Store the recording under the turn's name and queue the turn.
     */
    private function queueTurn(Debate $debate, UploadedFile $recording, string $extension, string $turn): JsonResponse
    {
        $path = $recording->storeAs(
            "debates/{$debate->id}/recordings",
            "{$turn}.{$extension}",
            config('debate.audio.disk'),
        );

        if ($path === false) {
            throw new RuntimeException('The recording could not be stored.');
        }

        ProcessVoiceDebate::dispatch($debate, $path);

        // Remembered for a day even if the recording is later deleted (a failed turn drops it), so that a late
        // retry of the same upload is still recognised.
        Cache::put($this->receivedKey($debate, $turn), true, now()->addDay());

        return $this->accepted($turn);
    }

    /**
     * Whether this turn was already received: a recording under its name (any format), a message that refers to
     * it, or the record kept after it was queued.
     */
    private function alreadyReceived(Debate $debate, string $turn): bool
    {
        if (Cache::has($this->receivedKey($debate, $turn))) {
            return true;
        }

        $folder = "debates/{$debate->id}/recordings";
        $disk = Storage::disk(config('debate.audio.disk'));
        $paths = array_map(fn (string $extension) => "{$folder}/{$turn}.{$extension}", array_unique(array_values(config('debate.audio.formats'))));

        foreach ($paths as $path) {
            if ($disk->exists($path)) {
                return true;
            }
        }

        return $debate->messages()
            ->where('role', MessageRole::User)
            ->whereIn('audio_path', $paths)
            ->exists();
    }

    private function accepted(string $turn): JsonResponse
    {
        return response()->json(['status' => 'processing', 'turn' => $turn], 202);
    }

    private function lockKey(Debate $debate, string $turn): string
    {
        return "debate-audio-lock:{$debate->id}:{$turn}";
    }

    private function receivedKey(Debate $debate, string $turn): string
    {
        return "debate-audio-received:{$debate->id}:{$turn}";
    }
}
