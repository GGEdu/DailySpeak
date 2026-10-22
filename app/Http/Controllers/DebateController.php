<?php

namespace App\Http\Controllers;

use App\Http\Resources\DebateResource;
use App\Models\NewsArticle;
use App\Services\Guided\DebateStarter;
use Illuminate\Http\Request;

class DebateController extends Controller
{
    /**
     * Start a debate about a news article, or resume the user's active one (201 vs 200).
     */
    public function store(Request $request, NewsArticle $newsArticle, DebateStarter $starter): DebateResource
    {
        return new DebateResource($starter->start($request->user(), $newsArticle));
    }
}
