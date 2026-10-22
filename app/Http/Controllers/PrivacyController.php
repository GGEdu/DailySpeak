<?php

namespace App\Http\Controllers;

use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class PrivacyController extends Controller
{
    /**
     * The public privacy notice. The retention periods come from the configuration, so the notice cannot drift from
     * what the scheduled deletions actually do.
     */
    public function show(): Response
    {
        return Inertia::render('Privacy', [
            'version' => User::CONSENT_VERSION,
            'responsible' => [
                'controller' => config('privacy.controller'),
                'contact_email' => config('privacy.contact_email'),
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
