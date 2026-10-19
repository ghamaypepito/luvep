<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Restricted modules
    |--------------------------------------------------------------------------
    |
    | Modules listed here are HIDDEN by default and must be granted per-user by
    | a super admin from Module Management. Super admins always see them.
    |
    | Every module that already exists in the app is GRANDFATHERED — it is NOT
    | listed here, so it stays visible exactly as it is today (role-based). Only
    | add a module here when it should ship hidden-by-default until granted.
    |
    | Keyed by a stable module key (used on the users.module_permissions array
    | and on the frontend nav item's `module` field).
    |
    | A module may expose `features` — grantable SUB-PARTS. A grant may then be
    | the whole module key (`dtr`, implies every feature) or a dotted feature
    | key (`dtr.reports`). `admin_default: true` marks a module admins + super
    | admins always see (it was historically an admin surface); such a module is
    | additionally grantable to non-admin staff. Without it, only super admins
    | see the module until granted (agents, program_verification).
    |
    */
    'restricted' => [
        'hr' => [
            'label' => 'Human Resources',
            'description' => 'Private employee services. Sensitive features require explicit individual grants.',
            'features' => array_combine(config('hr.abilities'), array_map(fn ($ability) => ['label' => ucfirst(str_replace('.', ' ', $ability)), 'description' => 'Explicit HR permission'], config('hr.abilities'))),
        ],
        'agents' => [
            'label' => 'Agents',
            'description' => 'Manage referral agents — their leads, profiles, and agreements.',
        ],
        // Historically "Program Verification"; the key is kept so existing grants
        // keep working. Now a two-part "Verification" module: the Proposal queue
        // and the Consultancy Agreement queue. A whole-module grant covers both;
        // each part is also grantable on its own.
        'program_verification' => [
            'label' => 'Verification',
            'description' => 'Verify and approve study proposals and consultancy agreements before they reach the client.',
            // Admins + super admins always see it (in the admin panel); still
            // grantable to department staff (e.g. Education) via Module Management.
            'admin_default' => true,
            'features' => [
                'proposal' => [
                    'label' => 'Proposal',
                    'description' => 'Verify and approve study proposals (programmes) before they reach the client.',
                ],
                'consultancy' => [
                    'label' => 'Consultancy Agreement',
                    'description' => 'Verify and approve consultancy agreements & their fees before they reach the client.',
                ],
            ],
        ],
        'dtr' => [
            'label' => 'DTR',
            'description' => 'Daily Time Record admin — grant the whole module or specific parts.',
            'admin_default' => true,
            'features' => [
                'reports' => [
                    'label' => 'Team Daily Reports',
                    'description' => 'See who submitted their end-of-day report and read each report.',
                ],
                'manage' => [
                    'label' => 'Setup Manager',
                    'description' => 'Configure staff schedules, timezones and hours; archive staff.',
                ],
                'summary' => [
                    'label' => 'Summary / Analytics',
                    'description' => 'Team DTR summary and analytics.',
                ],
            ],
        ],
        'portal_invitation' => [
            'label' => 'Portal Invitations',
            'description' => 'Approve/reject/revoke client portal invitations and generate credentials.',
            'admin_default' => true,
        ],
        // Read-only Students list inside the Agent / Sub-agent portals, scoped
        // to that portal's own referrals. Deliberately NOT admin_default: it
        // ships hidden and a super admin grants it per agent from Module
        // Management, so giving one agent visibility doesn't give it to all.
        'referral_students' => [
            'label' => 'Students (referral portals)',
            'description' => 'Let a recruiting agent or sub-agent see the students their own referrals became. Read-only — no edits, no deletes.',
        ],
        // Cases list + Case Profile inside the Agent portal, scoped to the
        // agent's OWN referrals that have become immigration cases. Unlike
        // referral_students this grants full case management (stage, documents,
        // financials, agreements) — but only over the agent's own referrals,
        // enforced row-by-row by the `case.owner` middleware, never every case.
        // Ships hidden; a super admin grants it per agent from Module Management.
        'referral_cases' => [
            'label' => 'Cases (referral portal)',
            'description' => "Let a recruiting agent see a list of the immigration cases their own referrals became. Read-only — scoped to that agent's referrals.",
        ],
    ],

];
