<?php

namespace App\Enums;

/**
 * Author of a debate message, mirroring the chat roles used by LLM APIs.
 */
enum MessageRole: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
