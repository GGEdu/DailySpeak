<?php

use App\Http\Controllers\DebateAudioController;
use App\Http\Controllers\DebateController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/news-articles/{newsArticle}/debates', [DebateController::class, 'store'])
        ->name('debates.store');

    // Every upload costs an STT + LLM + TTS round trip, hence the rate limit (admins are exempt).
    Route::post('/debates/{debate}/audio', [DebateAudioController::class, 'store'])
        ->middleware('throttle:debate-audio')
        ->name('debates.audio.store');
});
