<?php

namespace App\Http\Middleware;

use App\Services\AiSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the AI settings an admin saved into config/ before the request runs (App\Services\AiSettings::apply()).
 */
class ApplyAiSettings
{
    public function handle(Request $request, Closure $next): Response
    {
        app(AiSettings::class)->apply();

        return $next($request);
    }
}
