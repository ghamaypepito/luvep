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
        'policies.publish', 'cases.manage', 'cases.assigned', 'operations.manage', 'reports', 'access.manage', 'audit',
    ],
    'grant_labels' => [
        'people' => 'Browse employee directory', 'enroll' => 'Enroll employees and assign reviewers', 'profile.view' => 'Read employee profiles', 'profile.manage' => 'Manage employee profiles', 'preview' => 'Read-only employee preview',
        'leave.review' => 'Department Head leave decisions', 'leave.fallback' => 'Fallback leave decisions', 'leave.emergency' => 'Short-notice annual leave exceptions', 'dtr.review' => 'Review time records', 'dtr.correct' => 'Correct closed clock records',
        'documents.manage' => 'Manage general employee documents', 'documents.medical' => 'Access medical documents', 'documents.compensation' => 'Access compensation documents', 'documents.case' => 'Access confidential case documents',
        'policies.publish' => 'Publish policies', 'cases.manage' => 'Manage all HR support cases', 'cases.assigned' => 'Handle assigned HR support cases', 'operations.manage' => 'Manage onboarding, equipment and milestones', 'reports' => 'Run reports across all employees', 'access.manage' => 'Administer HR permissions', 'audit' => 'Read HR audit and delivery history',
    ],
    'admin_defaults' => ['people', 'enroll', 'profile.view', 'profile.manage', 'preview'],
];
