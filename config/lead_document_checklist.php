<?php

/**
 * Server-side mirror of resources/js/data/leadDocumentChecklist.js.
 *
 * Both files are the authoritative source of truth for the general lead
 * documents checklist — the frontend copy drives the staff Documents tab
 * on the lead detail page, this copy drives the public tracker
 * (/track/{code}) "What we still need" section for leads/students that
 * aren't visa-linked (immigration cases keep using VisaType.checklist_items).
 *
 * KEEP IN SYNC: any edit here needs the matching edit on the JS side.
 * The item `id` is the checklist_key stored on LeadDocument rows; changing
 * an id orphans previously-uploaded files.
 */

return [
    // Client education intake requirements. Staff retain the wider checklist;
    // immigration cases continue using their visa-specific requirements.
    'education_sections' => [
        ['section' => 'Education Documents', 'items' => [
            ['id' => 'pers.passport', 'name' => 'Passport'],
            ['id' => 'acad.cv', 'name' => 'Curriculum Vitae'],
            ['id' => 'acad.degree_diploma', 'name' => 'Academic Certificates'],
            ['id' => 'acad.transcript', 'name' => 'Academic Transcripts'],
        ]],
        ['section' => 'Other Documents', 'items' => [
            ['id' => 'pers.certificate_employment', 'name' => 'Employment Certificate',
                'required' => false, 'hint' => 'May be required by the institution.'],
        ]],
    ],
    // Section order matters — it drives the visual order of the checklist
    // on both the staff Documents tab and the public tracker. Offer &
    // Academic leads because that's the first tranche of documents sales
    // chases from a new student lead; agreements/info-form follow only
    // once the offer is in. Keep in sync with the JS CHECKLIST.
    'sections' => [
        [
            'key' => 'academic',
            'section' => 'Offer and Academic Documents',
            'items' => [
                ['id' => 'acad.cv', 'name' => 'Curriculum Vitae'],
                ['id' => 'acad.offer_of_place', 'name' => 'Offer of Place', 'hint' => 'Official letter from the New Zealand university.'],
                ['id' => 'acad.degree_diploma', 'name' => 'Degree Certificate or Diploma'],
                ['id' => 'acad.transcript', 'name' => 'Official Transcript of Records (TOR)', 'hint' => 'From previous school(s) showing subjects, grades, and degree awarded.'],
                ['id' => 'acad.english_test', 'name' => 'PTE / IELTS Academic Test Result'],
                ['id' => 'acad.letter_of_intent', 'name' => 'Letter of Intent', 'hint' => "Applicant's stated reasons for studying in NZ and study goals."],
                ['id' => 'acad.tuition_proof', 'name' => 'Proof of Tuition Fee Payment', 'hint' => 'For applications already in NZ.'],
            ],
        ],
        [
            'key' => 'agreements',
            'section' => 'Agreements',
            'items' => [
                ['id' => 'agree.proposal', 'name' => 'Study Proposal'],
                ['id' => 'agree.consultancy', 'name' => 'Consultancy Agreement'],
                ['id' => 'agree.engagement_english', 'name' => 'Engagement Agreement for English Review'],
            ],
        ],
        [
            'key' => 'personal',
            'section' => 'Personal Documents',
            'items' => [
                ['id' => 'pers.passport', 'name' => 'Valid Passport (Original & Copy)', 'hint' => 'Must be valid for the duration of study plus return date. Include clear copies of all identity pages and current visas/stamps.'],
                ['id' => 'pers.passport_photos', 'name' => 'Passport Photos (2 digital images)', 'hint' => '900×1200 px and 2250×3000 px, 500 KB – 3 MB each, JPG, portrait, plain background.'],
                ['id' => 'pers.certificate_employment', 'name' => 'Certificate of Employment', 'hint' => 'From current or last employer — position, dates, and duties.'],
            ],
        ],
        [
            'key' => 'immigration_forms',
            'section' => 'Immigration Forms',
            'items' => [
                ['id' => 'imm.inz1012', 'name' => 'Student Visa Application Form — INZ1012'],
                ['id' => 'imm.inz1226', 'name' => 'Student Visa Declaration — INZ1226'],
                ['id' => 'imm.inz1014', 'name' => 'Financial Undertaking for Student (Sponsorship) — INZ1014'],
            ],
        ],
        [
            'key' => 'employment_applicant',
            'section' => 'Employment and Financial Documents (Applicant)',
            'items' => [
                ['id' => 'appfin.coe', 'name' => 'Certificate of Employment / Work Reference', 'hint' => 'From last employer — position, dates, duties.'],
                ['id' => 'appfin.payslips', 'name' => 'Latest 3 Payslips', 'hint' => 'From current or last employment.'],
                ['id' => 'appfin.bank_statements', 'name' => 'Bank Statements — Last 6 Months (Applicant)', 'hint' => 'Showing sufficient funds for tuition and living costs. For 18 months of study: minimum NZ$30,000 living funds.'],
                ['id' => 'appfin.savings', 'name' => 'Proof of Savings / Fixed Deposits / Investments'],
                ['id' => 'appfin.property', 'name' => 'Property Ownership Documents', 'hint' => 'Title deeds, tax declarations, or certificates.'],
                ['id' => 'appfin.business_permit', 'name' => 'Business Permit / Registration', 'hint' => 'Proof of business ownership or registration (if self-employed).'],
            ],
        ],
        [
            'key' => 'employment_sponsor',
            'section' => 'Employment and Financial Documents (Sponsor)',
            'items' => [
                ['id' => 'spfin.bank_statements', 'name' => "Sponsor's Bank Statements", 'hint' => 'Last 6 months — sufficient funds for tuition + living costs.'],
                ['id' => 'spfin.support_letter', 'name' => "Sponsor's Letter of Support", 'hint' => 'Confirming financial support and relationship to applicant.'],
                ['id' => 'spfin.passport', 'name' => "Sponsor's Passport Copy"],
                ['id' => 'spfin.birth_cert', 'name' => "Sponsor's Birth Certificate"],
                ['id' => 'spfin.photos', 'name' => 'Photos with Sponsor', 'hint' => 'Supporting relationship / proof of closeness.'],
                ['id' => 'spfin.business_permit', 'name' => "Sponsor's Business Permit / Registration"],
                ['id' => 'spfin.itr', 'name' => 'Income Tax Return (if self-employed)'],
                ['id' => 'spfin.relationship_docs', 'name' => 'Government-Issued Relationship Documents', 'hint' => 'PSA Marriage Cert, PSA Birth Cert, etc. proving relationship.'],
            ],
        ],
        [
            'key' => 'partner',
            'section' => 'Documents of Partner / Spouse',
            'items' => [
                ['id' => 'part.passport', 'name' => 'Passport of Partner'],
                ['id' => 'part.passport_photos', 'name' => "Partner's Passport Photos (2 digital images)"],
                ['id' => 'part.police_clearance', 'name' => 'Police Clearance Certificate (NBI / PNP)'],
                ['id' => 'part.birth_cert', 'name' => 'Birth Certificate of Partner'],
            ],
        ],
        [
            'key' => 'partnership_proofs',
            'section' => 'Proofs of Partnership',
            'items' => [
                ['id' => 'prtn.timeline', 'name' => 'Relationship Timeline', 'hint' => 'Chronological summary of your relationship milestones.'],
                ['id' => 'prtn.marriage_cert', 'name' => 'Marriage or Civil Union Certificates', 'hint' => 'PSA-certified copy.'],
                ['id' => 'prtn.children_bc', 'name' => 'Birth Certificates of Children You Share', 'hint' => 'PSA-certified copies.'],
                ['id' => 'prtn.children_ppt', 'name' => 'Passport of Any Children You Share'],
                ['id' => 'prtn.joint_property', 'name' => 'Joint Ownership of Residential Property or Home Loan', 'hint' => 'Title deeds, mortgage agreements, loan statements.'],
                ['id' => 'prtn.joint_utilities', 'name' => 'Joint Utility Accounts', 'hint' => 'Power, water, internet, phone bills in both names.'],
                ['id' => 'prtn.shared_mail', 'name' => 'Mail Sent to Shared Address', 'hint' => 'Letters, bills, statements addressed to both.'],
                ['id' => 'prtn.joint_bank', 'name' => 'Joint Bank Accounts', 'hint' => 'Frequent shared transactions.'],
                ['id' => 'prtn.joint_assets', 'name' => 'Joint Ownership of Any Assets', 'hint' => 'Vehicles, appliances, etc.'],
                ['id' => 'prtn.joint_credit', 'name' => 'Joint Credit Cards or Hire Purchase Agreements'],
                ['id' => 'prtn.gov_partnership', 'name' => 'Government Documents Declaring Partnership', 'hint' => 'SSS, PhilHealth, GSIS, etc.'],
                ['id' => 'prtn.fin_agreements', 'name' => 'Mutually Agreed Financial Arrangements', 'hint' => 'Written agreements showing shared financial responsibilities.'],
                ['id' => 'prtn.correspondence', 'name' => 'Correspondence Between Partners', 'hint' => 'Cards, letters, emails, chat records.'],
                ['id' => 'prtn.social_media', 'name' => 'Social Media Posts or Photos Together'],
                ['id' => 'prtn.events', 'name' => 'Events Attended Together', 'hint' => 'Invitations or photos from birthdays, weddings, etc.'],
                ['id' => 'prtn.travels', 'name' => 'Travels Together', 'hint' => 'Flight tickets, hotel bookings, travel photos.'],
                ['id' => 'prtn.letters_support', 'name' => 'Letters of Support Recognizing Your Partnership', 'hint' => 'From family, neighbors, friends.'],
            ],
        ],
        [
            'key' => 'additional',
            'section' => 'Additional Documents',
            'items' => [
                ['id' => 'add.previous_visas', 'name' => 'Previous Visa Copies', 'hint' => 'If applicable.'],
                ['id' => 'add.other_certs', 'name' => 'Other Relevant Certificates', 'hint' => 'Awards, diplomas, professional certificates.'],
            ],
        ],
    ],
];
