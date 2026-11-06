<?php

return [
    'ui_enabled' => env('EP_AGENT_UI_ENABLED', false),
    'pilot_admins' => env('EP_AGENT_PILOT_ADMINS', false),
    'pilot_user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('EP_AGENT_PILOT_USERS', ''))), fn ($id) => $id > 0)),
    'enabled' => env('EP_AGENT_ENABLED', false),
    // No provider transport exists in the foundation release.
    'paid_enabled' => false,
    'price_versions' => [], // Provision only after model prices and the budget are approved.
    'portals' => ['education', 'immigration'],
];
