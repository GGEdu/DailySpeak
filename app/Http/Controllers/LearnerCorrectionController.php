<?php

namespace App\Http\Controllers;

use App\Enums\MessageRole;
use App\Models\Debate;
use App\Models\DebateMessage;
use App\Services\Guided\LearnerCorrections;
use Illuminate\Http\Response;

/**
 * The learner's "I did not say that" on a written correction of one of their turns.
 */
class LearnerCorrectionController extends Controller
{
    /**
     * Dismiss the correction at the index: it leaves the report. Dismissing it again changes nothing.
     */
    public function dismiss(Debate $debate, DebateMessage $message, int $index, LearnerCorrections $corrections): Response
    {
        abort_unless($message->role === MessageRole::User, 404);
        abort_unless($corrections->dismiss($message, $index), 404);

        return response()->noContent();
    }
}
