<?php

return [
    'enabled' => (bool) env('HR_ENABLED', false),
    'host' => env('HR_HOST', 'hr.luvep.com'),
    'url' => env('HR_URL', 'https://'.env('HR_HOST', 'hr.luvep.com')),
    'main_url' => env('HR_MAIN_URL', 'https://luvep.com'),
    'notifications_enabled' => (bool) env('HR_NOTIFICATIONS_ENABLED', false),
    'session_hosts' => array_filter(array_map('trim', explode(',', env('HR_SESSION_HOSTS', 'luvep.com,hr.luvep.com')))),
    'session_domain' => env('HR_SESSION_DOMAIN', '.luvep.com'),
    'shared_session' => (bool) env('HR_SHARED_SESSION', false),
    'internal_roles' => ['super_admin', 'admin', 'sales', 'education', 'english', 'immigration', 'immigration_manager', 'immigration_adviser', 'accommodation', 'finance'],
    'abilities' => [
        'people', 'enroll', 'profile.view', 'profile.manage', 'preview',
        'leave.review', 'leave.fallback', 'leave.emergency', 'dtr.review', 'dtr.correct',
        'documents.manage', 'documents.medical', 'documents.compensation', 'documents.case',
        'policies.publish', 'cases.manage', 'operations.manage', 'reports', 'access.manage', 'audit',
    ],
    'admin_defaults' => ['people', 'enroll', 'profile.view', 'profile.manage', 'preview'],
];
