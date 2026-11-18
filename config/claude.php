<?php

return [
    'api_key' => env('ANTHROPIC_API_KEY'),
    'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
    'api_version' => '2026-10-21',
    'timeout_seconds' => 35,
    'max_tokens' => ['pathy' => 600, 'staff' => 1500],
    'instructions' => [
        'pathy' => 'You are Pathy, EP’s public education and services guide. Be friendly and concise. Help visitors find published programs, request a free assessment at /free-assessment or book a consultation at /booking. Use only supplied public information. Ask one focused question when information is missing.',
        'staff' => 'You are EP Assistant, helping EP staff draft communications and understand the records they are authorized to access. Be concise and factual. Clearly distinguish drafts from actions and explain when information is not on file.',
    ],
];
