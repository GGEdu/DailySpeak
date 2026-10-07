<?php

use App\Http\Controllers\Admin\NewsSourceController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\DebateSessionController;
use App\Http\Controllers\FeedController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check() ? to_route('feed') : Inertia::render('Welcome');
})->name('home');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:6,1');

    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->middleware('throttle:6,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('/feed', FeedController::class)->name('feed');

    Route::post('/news-articles/{newsArticle}/debate', [DebateSessionController::class, 'store'])
        ->name('debates.start');
    Route::get('/debates/{debate}', [DebateSessionController::class, 'show'])
        ->can('view', 'debate')
        ->name('debates.show');
    Route::post('/debates/{debate}/finish', [DebateSessionController::class, 'finish'])
        ->can('finish', 'debate')
        ->name('debates.finish');
});

Route::middleware(['auth', 'can:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/sources', [NewsSourceController::class, 'index'])->name('sources.index');
    Route::post('/sources', [NewsSourceController::class, 'store'])->name('sources.store');
    Route::patch('/sources/{source}', [NewsSourceController::class, 'update'])->name('sources.update');
    Route::delete('/sources/{source}', [NewsSourceController::class, 'destroy'])->name('sources.destroy');
    Route::post('/sources/{source}/fetch', [NewsSourceController::class, 'fetch'])->name('sources.fetch');
});
