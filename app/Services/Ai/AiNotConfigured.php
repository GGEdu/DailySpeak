<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The user has no usable connection for a task: no key for the provider they picked, or none picked at all, and the
 * platform is not allowed for them. Never answered with the platform's key on the user's behalf.
 *
 * The web answers with an error under the "ai" key (a link to Settings → AI); the API with 409 and code
 * "ai_not_configured" (see bootstrap/app.php).
 */
class AiNotConfigured extends RuntimeException
{
    public const CODE = 'ai_not_configured';

    public const MESSAGE = 'Add your AI key in Settings → AI.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
