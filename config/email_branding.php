<?php

/*
|--------------------------------------------------------------------------
| Per-portal email branding presets
|--------------------------------------------------------------------------
|
| A message template's `branding` value selects which set of banner (top
| header) + footer (CTA image, above the contact block) the branded email
| shell uses. Paths are relative to public/. Any file that isn't present
| falls back to the default ePathways artwork, so a portal without its own
| assets still sends a clean email.
|
| To give a portal its own look, drop its images at the paths below:
|   public/images/email/branding/<portal>-banner.png   (wide, ~600px)
|   public/images/email/branding/<portal>-cta.png      (CTA / consultation)
|
| A per-template uploaded banner/footer still overrides the preset.
|
*/

return [
    'default' => [
        'label' => 'Default EP',
        'banner' => 'images/email/team-header.png',
        'footer' => 'images/coffee-cta.png',
    ],
    'sales' => [
        'label' => 'Sales',
        'banner' => 'images/email/branding/sales-banner.png',
        'footer' => 'images/email/branding/sales-cta.png',
    ],
    'education' => [
        'label' => 'Education',
        'banner' => 'images/email/branding/education-banner.png',
        'footer' => 'images/email/branding/education-cta.png',
    ],
    'english' => [
        'label' => 'English',
        'banner' => 'images/email/branding/english-banner.png',
        'footer' => 'images/email/branding/english-cta.png',
    ],
    'immigration' => [
        'label' => 'Immigration',
        'banner' => 'images/email/branding/immigration-banner.png',
        'footer' => 'images/email/branding/immigration-cta.png',
    ],
    'accommodation' => [
        'label' => 'Accommodation',
        'banner' => 'images/email/branding/accommodation-banner.png',
        'footer' => 'images/email/branding/accommodation-cta.png',
    ],
    'finance' => [
        'label' => 'Finance',
        'banner' => 'images/email/branding/finance-banner.png',
        'footer' => 'images/email/branding/finance-cta.png',
    ],
    'agent' => [
        'label' => 'Affiliate Partner',
        'banner' => 'images/email/branding/agent-banner.png',
        'footer' => 'images/email/branding/agent-cta.png',
    ],
];
