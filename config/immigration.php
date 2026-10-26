<?php

return [

    /*
    |--------------------------------------------------------------------------
    | IAA licence expiry warnings
    |--------------------------------------------------------------------------
    |
    | Days-before-expiry at which a licensed adviser (and the admins) are
    | actively notified that their IAA licence is approaching its cliff. Build
    | 12 §2 made the licence load-bearing — an expired/absent expiry silently
    | closes AdviceBearingPolicy, so nobody could record a verdict on a Monday.
    | The `immigration:licence-expiry-check` command reads this list.
    |
    | The dashboard IaaComplianceCard is the always-on passive backstop; these
    | thresholds drive the active push notification.
    |
    */
    'licence_warning_days' => [30, 14],

    /*
    |--------------------------------------------------------------------------
    | Case custody ageing (Build 12 phase 2)
    |--------------------------------------------------------------------------
    |
    | The Cases board colours a case by how long it has been STUCK — days since
    | `last_activity_at` — not by how long the current owner has held it. A case
    | someone is actively working for twelve days is not a problem; a case
    | untouched for ten is. The "With" column shows the ownership duration as
    | plain text; these thresholds drive the colour.
    |
    | amber = getting stale, red = stuck. Days, on last_activity_at. Starting
    | values, not a decision (Build 12 §14.3) — tune from real dwell times.
    |
    */
    'custody_stale_amber_days' => 6,
    'custody_stale_red_days' => 10,

    /*
    |--------------------------------------------------------------------------
    | Case assist — findings rules (Build 12 phase 3)
    |--------------------------------------------------------------------------
    |
    | Thresholds the rules engine reads. Kept here (not hardcoded) because rule
    | noise is the failure mode: if a typical case surfaces a wall of findings,
    | staff stop reading the panel. Tune from the dismissal rate per finding_key.
    |
    */
    'findings' => [
        'doc_request_unanswered_days' => 5,
        'no_contact_days' => 14,
        'passport_expiry_months' => 6,
        // Grace before an invoiced-but-unpaid case is flagged (Build 12 phase
        // 4.5 — the invoice-overdue rule graduated off "couldn't verify" once
        // case_payments exists).
        'payment_overdue_days' => 7,
        // Age (days) an answer-requiring thread may sit unresolved before the
        // UnresolvedThreadRule flags it (Build 12 phase 6 — graduated off
        // "couldn't verify" once case_threads exists).
        'thread_unanswered_days' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Process chain — step ownership resolver (Build 12 phase 4.5)
    |--------------------------------------------------------------------------
    |
    | Maps a template owner_role (a process function) to a user id. Env-specific;
    | leave null and a human assigns via handoff. `adviser` also falls back to
    | the sole licensed adviser when unset. Set these per deployment.
    |
    */
    'step_owners' => [
        'coordinator' => null,
        'qc' => null,
        'ops' => null,
        'adviser' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default signing adviser
    |--------------------------------------------------------------------------
    |
    | The licensed adviser pre-selected as the signer when staff create an
    | engagement. Matched by name (case-insensitive) against the licensed
    | advisers list; falls back to the current user / first licensed adviser
    | when no match exists. Set to the practice's LIA.
    |
    */
    'signing_adviser' => env('IMMIGRATION_SIGNING_ADVISER', 'Hendry Dai'),

    /*
    |--------------------------------------------------------------------------
    | Installment payment plans (pay-later)
    |--------------------------------------------------------------------------
    |
    | When a client chooses "pay later", the fee is paid WEEKLY across a
    | staff-chosen plan. Staff set the plan length (one of the presets below)
    | and the upfront deposit % (which covers the INZ application fee) when
    | they generate the engagement + invoice. All knobs here so the terms can
    | change without code.
    |
    | plan_presets: plan length (months) => number of weekly payments. Add or
    | remove presets freely; the staff UI offers exactly these.
    | deposit_percent_*: the allowed band + default for the upfront deposit.
    |
    */
    'installments' => [
        'plan_presets' => [
            1 => 4,
            2 => 9,
            3 => 13,
            6 => 26,
        ],
        'deposit_percent_default' => 25,
        'deposit_percent_min' => 20,
        'deposit_percent_max' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fees — admin fee
    |--------------------------------------------------------------------------
    |
    | A flat admin fee added to every engagement total, for BOTH pay-now and
    | pay-later. Editable per case; this is the default. `admin_fee_gst` toggles
    | whether GST is added on top of the admin fee (default: flat, no GST).
    |
    */
    'fees' => [
        'admin_fee' => 10,
        'admin_fee_gst' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Onboarding deadlines (pre-screening / application)
    |--------------------------------------------------------------------------
    |
    | After the adviser sends the written agreement the client is expected to
    | SIGN within `sign_days`, and after signing to CHOOSE pay-now/pay-later
    | within `choose_days`. These drive the countdowns shown to staff + client
    | and the automated reminder emails.
    |
    */
    'onboarding' => [
        'sign_days' => 5,
        'choose_days' => 5,
    ],

];
