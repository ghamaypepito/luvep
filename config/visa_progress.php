<?php

/*
|--------------------------------------------------------------------------
| INZ-style visa application sub-status tracker
|--------------------------------------------------------------------------
|
| Config-driven catalogue for the sub-status tracker shown on an immigration
| case once it reaches lodgement (mirrors the applicant-facing INZ "Your visa
| application progress" view). The activity set, labels, descriptions and the
| status vocabulary all live here so they can change without touching call
| sites. Consumed by App\Services\Immigration\VisaProgressService.
|
| Storage: leads.visa_progress (JSON). Lodge / outcome datetimes reuse the
| existing leads.inz_lodged_at / inz_decision_at columns — no new datetime col.
|
*/

return [

    // The five INZ activity statuses, in display order, each with its label and
    // the legend text copied verbatim from the INZ progress screen.
    'statuses' => [
        'not_started' => [
            'label' => 'Not started',
            'legend' => 'The activity has not yet begun. No action is required for the activity at this time.',
        ],
        'in_progress' => [
            'label' => 'In progress',
            'legend' => 'We are reviewing the information that you or a third-party has submitted.',
        ],
        'info_requested' => [
            'label' => 'Info requested',
            'legend' => 'We have contacted you or the required third-party for additional information.',
        ],
        'completed' => [
            'label' => 'Completed',
            'legend' => 'We have received and verified the information you or a third-party has submitted. No further action is required for the activity at this time.',
        ],
        'not_applicable' => [
            'label' => 'Not applicable',
            'legend' => 'The activity is not required for this application.',
        ],
    ],

    // The default status a freshly-initialised activity starts on.
    'default_status' => 'not_started',

    // Immigration stages (Lead::IMMIGRATION_STAGES) for which the tracker is
    // shown and editable — i.e. lodgement through to a decision. Reaching the
    // first of these initialises the activity set (all "Not started").
    'tracked_stages' => [
        'Visa Lodged',
        'Interim Visa Issued',
        'Request for Information',
        'RFI Responded',
        'Approved in Principle',
        'Approved Visa',
        'Decline Visa',
    ],

    // The lead datetime column that holds the "visa lodge" date surfaced beside
    // the tracker (already exists — not duplicated into the JSON).
    'lodged_at_column' => 'inz_lodged_at',
    'decision_at_column' => 'inz_decision_at',

    // The activity catalogue, in display order, with the group ("Status" column
    // on the INZ screen) and default description copied verbatim from the image.
    // Staff can override a description per case; these are the starting text.
    'activities' => [
        [
            'key' => 'application_received',
            'label' => 'Application received',
            'group' => 'Preparing Application',
            'description' => 'We have received your application.',
        ],
        [
            'key' => 'identity_check',
            'label' => 'Identity check',
            'group' => 'Preparing Application',
            'description' => 'We have confirmed the identity of the applicants included in your application.',
        ],
        [
            'key' => 'documents_checks',
            'label' => 'Documents checks',
            'group' => 'Preparing Application',
            'description' => 'We have confirmed that your documents are in the correct format.',
        ],
        [
            'key' => 'medical_checks',
            'label' => 'Medical checks',
            'group' => 'Gathering Information',
            'description' => 'This application did not need any medical examinations.',
        ],
        [
            'key' => 'third_party_checks',
            'label' => 'Third-party checks',
            'group' => 'Gathering Information',
            'description' => 'We have completed the third-party checks required to assess your application.',
        ],
        [
            'key' => 'sponsor_check',
            'label' => 'Sponsor check',
            'group' => 'Gathering Information',
            'description' => 'This application did not have a sponsor.',
        ],
        [
            'key' => 'assessment',
            'label' => 'Assessment',
            'group' => 'Under Assessment',
            'description' => 'We have all the information we need at this time and are assessing your application.',
        ],
    ],
];
