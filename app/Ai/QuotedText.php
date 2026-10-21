<?php

namespace App\Ai;

/**
 * Text that a prompt quotes as data, between tags such as <article_text>. Its angle brackets are escaped, so no
 * tag can be written inside it, not even one rebuilt from pieces. Removing the tags would not do: removing
 * "</article_text>" from "</article_</article_text>text>" leaves a closing tag behind.
 */
final class QuotedText
{
    public static function escape(string $text): string
    {
        return str_replace(['<', '>'], ['&lt;', '&gt;'], $text);
    }
}
