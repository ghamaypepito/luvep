<?php

return [
    'enabled' => env('EP_AGENT_ENABLED', false),
    // No provider transport exists in the foundation release.
    'paid_enabled' => false,
    'portals' => ['education', 'immigration'],
];
