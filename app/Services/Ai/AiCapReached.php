<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The user has used up the monthly number of AI calls they set for themselves (users.ai_monthly_cap). Answered with 429
 * and code "ai_cap_reached" in the API, and under the "ai" key on the web (see bootstrap/app.php).
 */
class AiCapReached extends RuntimeException
{
    public const CODE = 'ai_cap_reached';

    public const MESSAGE = 'The monthly AI limit you set is reached. Raise it in Settings → Usage.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
