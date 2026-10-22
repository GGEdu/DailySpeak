<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Captcha\AltchaChallenges;
use Illuminate\Http\JsonResponse;

class AltchaChallengeController extends Controller
{
    /**
     * A new human-check challenge for the widget on the sign-up and password reset pages.
     */
    public function show(AltchaChallenges $challenges): JsonResponse
    {
        return response()->json($challenges->issue()->toArray())
            ->header('Cache-Control', 'no-store');
    }
}
