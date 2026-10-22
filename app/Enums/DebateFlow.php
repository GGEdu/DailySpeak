<?php

namespace App\Enums;

/**
 * How a debate runs: "guided" starts from a brief of the article and a written opening question, reviews the
 * learner's turns in writing and finishes with the corrections in its report; "classic" is the original flow.
 * It is decided when the debate starts (see App\Services\Guided\GuidedSettings).
 */
enum DebateFlow: string
{
    case Guided = 'guided';
    case Classic = 'classic';
}
