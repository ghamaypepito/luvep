<?php

return [
    'enabled' => env('EP_AGENT_ENABLED', false),
    // No provider transport exists in the foundation release.
    'paid_enabled' => false,
    'price_versions' => [], // Provision only after model prices and the budget are approved.
    'portals' => ['education', 'immigration'],
];
