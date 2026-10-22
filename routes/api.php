<?php

use App\Http\Controllers\DebateAudioController;
use App\Http\Controllers\DebateBriefController;
use App\Http\Controllers\DebateController;
use App\Http\Controllers\LearnerCorrectionController;
use App\Http\Controllers\SaveWordController;
use App\Http\Controllers\WordLookupController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    // Only what the page needs: no password hash, verification date or admin flag.
    Route::get('/user', function (Request $request) {
        return $request->user()->only(['id', 'name', 'email', 'current_level']);
    });

    Route::post('/news-articles/{newsArticle}/debates', [DebateController::class, 'store'])
        ->name('debates.store');

    // The retry of a guided debate's brief: each attempt is a paid call. The debate's owner only.
    Route::post('/debates/{debate}/brief', [DebateBriefController::class, 'store'])
        ->middleware('throttle:debate-brief')
        ->can('view', 'debate')
        ->name('debates.brief.store');

    // "I did not say that": the learner dismisses a written correction of their own turn. The message must belong to the debate.
    Route::post('/debates/{debate}/messages/{message}/corrections/{index}/dismiss', [LearnerCorrectionController::class, 'dismiss'])
        ->whereNumber('index')
        ->can('view', 'debate')
        ->scopeBindings()
        ->name('debates.messages.corrections.dismiss');

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
