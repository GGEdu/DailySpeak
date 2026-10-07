<?php

namespace App\Http\Controllers;

use App\Http\Resources\DebateResource;
use App\Models\NewsArticle;
use Illuminate\Http\Request;

class DebateController extends Controller
{
    /**
     * Start a debate about a news article, or resume the user's active one (201 vs 200).
     */
    public function store(Request $request, NewsArticle $newsArticle): DebateResource
    {
        return new DebateResource($request->user()->startDebate($newsArticle));
    }
}
