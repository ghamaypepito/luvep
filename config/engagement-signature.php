<?php

return [
    // Configure only after the business owner confirms the intended team.
    'internal_user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('ENGAGEMENT_SIGNATURE_INTERNAL_USER_IDS', ''))))),
];
