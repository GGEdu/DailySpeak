<?php

use App\Http\Controllers\DebateAudioController;
use App\Http\Controllers\DebateController;
use App\Http\Controllers\SaveWordController;
use App\Http\Controllers\WordLookupController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/news-articles/{newsArticle}/debates', [DebateController::class, 'store'])
        ->name('debates.store');

    // Every upload costs an STT + LLM + TTS round trip, hence the rate limit (admins are exempt).
    Route::post('/debates/{debate}/audio', [DebateAudioController::class, 'store'])
        ->middleware('throttle:debate-audio')
        ->name('debates.audio.store');

    // Selecting a word in any text: translate and explain it, or save it to "Your words".
    Route::post('/lookups', WordLookupController::class)
        ->middleware('throttle:lookups')
        ->name('lookups.store');
    Route::post('/vocabulary', SaveWordController::class)
        ->middleware('throttle:lookups')
        ->name('vocabulary.store');
});
