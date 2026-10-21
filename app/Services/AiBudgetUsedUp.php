<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown by AiBudget::spendOrFail() when the shared daily AI budget is used up.
 */
class AiBudgetUsedUp extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(AiBudget::USED_UP_MESSAGE);
    }
}
