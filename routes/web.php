<?php

use App\Enums\EnglishLevel;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\GuidedDebateController;
use App\Http\Controllers\Admin\LimitsController;
use App\Http\Controllers\Admin\NewsSourceController;
use App\Http\Controllers\Auth\AltchaChallengeController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\DebateSessionController;
use App\Http\Controllers\FeedController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\VocabularyController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check() ? to_route('feed') : Inertia::render('Welcome');
})->name('home');

// The privacy notice: public, so it can be read before signing up.
Route::get('/privacy', [PrivacyController::class, 'show'])->name('privacy');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware(['throttle:6,1', 'throttle:login']);

    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware(['throttle:6,1', 'throttle:register']);

    // The human check's challenge. Each sign-up or reset page loads one. Its own counter (the third argument):
    // an unnamed throttle shares one per visitor with the 6-a-minute forms, and reloading would lock sign-up.
    Route::get('/altcha/challenge', [AltchaChallengeController::class, 'show'])
        ->middleware('throttle:20,1,altcha')
        ->name('altcha.challenge');

    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // A copy of the data and its erasure (GDPR art. 15-17, 20) do not wait for a confirmed address: someone who
    // mistyped it must still be able to delete the account.
    Route::get('/settings/export', [AccountController::class, 'export'])
        ->middleware('throttle:6,1') // builds the whole history in one response
        ->name('account.export');
    Route::delete('/settings/account', [AccountController::class, 'destroy'])
        ->middleware('throttle:6,1') // the password check is a guess target
        ->name('account.destroy');

    // Accounts that signed up before the notice accept it here, once (see EnsureEmailVerifiedAndConsented).
    Route::get('/consent', [ConsentController::class, 'show'])->name('consent.show');
    Route::post('/consent', [ConsentController::class, 'store'])->name('consent.store');
});

// Everything else needs a confirmed address: open sign-up must not mean open access to the AI budget.
Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('/feed', FeedController::class)->name('feed');

    Route::post('/news-articles/{newsArticle}/debate', [DebateSessionController::class, 'store'])
        ->name('debates.start');
    Route::get('/debates/{debate}', [DebateSessionController::class, 'show'])
        ->can('view', 'debate')
        ->name('debates.show');
    Route::post('/debates/{debate}/finish', [DebateSessionController::class, 'finish'])
        ->middleware('throttle:debate-finish') // each report is a paid evaluation
        ->can('finish', 'debate')
        ->name('debates.finish');

    Route::get('/vocabulary', [VocabularyController::class, 'index'])->name('vocabulary.index');
    Route::post('/vocabulary/{vocabulary}/review', [VocabularyController::class, 'review'])
        ->can('update', 'vocabulary')
        ->name('vocabulary.review');
    Route::post('/vocabulary/{vocabulary}/analyze', [VocabularyController::class, 'analyze'])
        ->middleware('throttle:lookups')
        ->can('update', 'vocabulary')
        ->name('vocabulary.analyze');
    Route::delete('/vocabulary/{vocabulary}', [VocabularyController::class, 'destroy'])
        ->can('delete', 'vocabulary')
        ->name('vocabulary.destroy');

    Route::get('/settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');
});

Route::middleware(['auth', 'verified', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/sources', [NewsSourceController::class, 'index'])->name('sources.index');
    Route::post('/sources', [NewsSourceController::class, 'store'])->name('sources.store');
    Route::patch('/sources/{source}', [NewsSourceController::class, 'update'])->name('sources.update');
    Route::delete('/sources/{source}', [NewsSourceController::class, 'destroy'])->name('sources.destroy');
    Route::post('/sources/{source}/fetch', [NewsSourceController::class, 'fetch'])->name('sources.fetch');

    Route::get('/limits', [LimitsController::class, 'index'])->name('limits.index');
    Route::put('/limits/{key}', [LimitsController::class, 'update'])->name('limits.update');
    Route::post('/limits/{key}/reset', [LimitsController::class, 'reset'])->name('limits.reset');

    // The guided debate switch per English level (App\Services\Guided\GuidedSettings).
    Route::put('/guided-debate/{level}', [GuidedDebateController::class, 'update'])
        ->whereIn('level', array_column(EnglishLevel::cases(), 'value'))
        ->name('guided-debate.update');
});
