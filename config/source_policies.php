<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Crawlers
    |--------------------------------------------------------------------------
    |
    | User-agent tokens of the crawlers AI companies announce. A robots.txt
    | rule that blocks one of them from an article page is reported to admins
    | on /admin/sources. Only the token matters: `*` is handled separately.
    |
    */

    'ai_crawlers' => [
        'GPTBot',
        'ChatGPT-User',
        'OAI-SearchBot',
        'ClaudeBot',
        'Claude-Web',
        'anthropic-ai',
        'Google-Extended',
        'CCBot',
        'PerplexityBot',
        'Bytespider',
        'Applebot-Extended',
        'meta-externalagent',
        'cohere-ai',
        'Amazonbot',
        'Diffbot',
    ],

    /*
    |--------------------------------------------------------------------------
    | Known Publisher Conditions
    |--------------------------------------------------------------------------
    |
    | Terms a publisher states outside its robots.txt. A source whose feed or
    | article pages are on one of these domains gets the note as a warning.
    | `domains` match the host and its subdomains.
    |
    */

    'publishers' => [
        [
            'domains' => ['theconversation.com'],
            'note' => 'The Conversation is licensed CC BY-ND: no derivative works, and a summary counts as one. Ask the publisher before using its text.',
        ],
        [
            'domains' => ['bbc.co.uk', 'bbc.com', 'bbci.co.uk'],
            'note' => 'The BBC licenses its content for personal, non-commercial use only.',
        ],
        [
            'domains' => ['theguardian.com', 'guardian.co.uk'],
            'note' => 'The Guardian prohibits LLM, AI and data-mining use of its content without permission. Its Open Platform API is the licensed route.',
        ],
        [
            'domains' => ['aljazeera.com', 'aljazeera.net'],
            'note' => 'Al Jazeera prohibits LLM, AI and data-mining use of its content without permission.',
        ],
    ],

];
