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
     * The reply is pushed to the debate's private channel (AIResponseGenerated).
     */
    public function store(StoreDebateAudioRequest $request, Debate $debate): JsonResponse
    {
        $recording = $request->file('audio');
        $extension = config('debate.audio.formats')[$recording->guessExtension()];

        $path = $recording->storeAs(
            "debates/{$debate->id}",
            Str::uuid().'.'.$extension,
            config('debate.audio.disk'),
        );

        if ($path === false) {
            throw new RuntimeException('The recording could not be stored.');
        }

        ProcessVoiceDebate::dispatch($debate, $path);

        return response()->json(['status' => 'processing'], 202);
    }
}
