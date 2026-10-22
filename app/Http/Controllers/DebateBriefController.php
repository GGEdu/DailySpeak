<?php

namespace App\Http\Controllers;

use App\Models\Debate;
use App\Services\Guided\DebateBriefs;
use Illuminate\Http\JsonResponse;

/**
 * The learner's retry of a guided debate's brief when it failed (the page's retry button).
 */
class DebateBriefController extends Controller
{
    /**
     * Prepare the brief again, or open the debate when its brief is already written. Queued: the answer is 202, and
     * the brief's state reaches the client by DebateBriefReady, DebateBriefFailed or a reload of the page.
     */
    public function store(Debate $debate, DebateBriefs $briefs): JsonResponse
    {
        abort_unless($debate->isGuided(), 409, 'This debate has no brief.');

        $briefs->retry($debate);

        return response()->json(['status' => $briefs->status($debate)], 202);
    }
}
