<?php

return [
    'owners' => [
        'Alaine' => env('ACCOMMODATION_ONBOARDING_ALAINE_EMAIL', \App\Services\Accommodation\AgreementTerms::SIGNATORY_EMAIL),
        'Janille' => env('ACCOMMODATION_ONBOARDING_JANILLE_EMAIL', 'janille@janilles.me'),
    ],
];
