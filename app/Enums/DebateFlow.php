<?php

namespace App\Enums;

/**
 * How a debate runs: "guided" starts from a brief of the article and a written opening question, reviews the
 * learner's turns in writing and finishes with the corrections in its report. Every new debate is guided; "classic"
 * is the flow debates started before the guided one had, and they keep it.
 */
enum DebateFlow: string
{
    case Guided = 'guided';
    case Classic = 'classic';
}
