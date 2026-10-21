<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDebateAudioRequest;
use App\Jobs\ProcessVoiceDebate;
use App\Models\Debate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use RuntimeException;

class DebateAudioController extends Controller
{
    /**
     * Receive a recorded voice turn and queue the tutor's answer.
     *
     * The reply is pushed to the debate's private channel (AIResponseGenerated). The 202 names the turn with the
     * recording's uuid, which the turn's events carry back, so the client can tell which turn each one answers.
     */
    public function store(StoreDebateAudioRequest $request, Debate $debate): JsonResponse
    {
        $recording = $request->file('audio');
        $extension = config('debate.audio.formats')[$recording->guessExtension()];
        $turn = (string) Str::uuid();

        $path = $recording->storeAs(
            "debates/{$debate->id}/recordings",
            "{$turn}.{$extension}",
            config('debate.audio.disk'),
        );

        if ($path === false) {
            throw new RuntimeException('The recording could not be stored.');
        }

        ProcessVoiceDebate::dispatch($debate, $path);

        return response()->json(['status' => 'processing', 'turn' => $turn], 202);
    }
}
