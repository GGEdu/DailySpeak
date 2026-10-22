<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Privacy\Responsible;
use Inertia\Inertia;
use Inertia\Response;

class PrivacyController extends Controller
{
    /**
     * The public privacy notice. The retention periods come from the configuration, so the notice cannot drift from
     * what the scheduled deletions actually do.
     */
    public function show(Responsible $responsible): Response
    {
        return Inertia::render('Privacy', [
            'version' => User::CONSENT_VERSION,
            // What an admin set on /admin/privacy, else the operator's values in .env (config/privacy.php).
            'responsible' => [
                'controller' => $responsible->controller(),
                'contact_email' => $responsible->contactEmail(),
            ],
            'retention' => [
                'recordings' => (int) config('debate.audio.retention_days'),
                // Null: the same period as the recordings (config/privacy.php).
                'replies' => (int) (config('privacy.replies_retention_days') ?? config('debate.audio.retention_days')),
                // 0: kept for as long as the account exists.
                'transcripts' => (int) config('privacy.transcripts_retention_days'),
            ],
        ]);
    }
}
