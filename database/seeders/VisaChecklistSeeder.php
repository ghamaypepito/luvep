<?php

namespace Database\Seeders;

use App\Models\VisaType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

/**
 * Seeds the checklist_items JSON field on each visa_type with the
 * required document list, sourced from the ePathways visa checklist
 * Word documents.
 *
 * Looks up visa_types by `code`. If a visa_type with the expected code
 * doesn't exist, logs a warning and skips. Does NOT create new visa_types.
 *
 * Idempotent — running multiple times overwrites checklist_items with
 * the same data.
 *
 * Schema used: { key, label, hint, required } per item.
 * Section grouping is embedded in the label as "Section · Item Name"
 * so staff and clients see the grouping visually without needing
 * schema-level section support.
 */
class VisaChecklistSeeder extends Seeder
{
    public function run(): void
    {
        $checklists = $this->checklists();

        foreach ($checklists as $code => $items) {
            $visaType = VisaType::where('code', $code)->first();

            if (! $visaType) {
                Log::warning("VisaChecklistSeeder: visa_type with code '{$code}' not found, skipping");
                $this->command->warn("Skipped: visa_type code '{$code}' not found");

                continue;
            }

            $visaType->checklist_items = $items;
            $visaType->save();

            $this->command->info("Seeded: {$visaType->name} ({$code}) — ".count($items).' items');
        }
    }

    private function item(string $key, string $section, string $label, string $hint, bool $required = true, ?string $fileCode = null, string $fileSuffix = ''): array
    {
        $entry = [
            'key' => $key,
            // Section is its own field so staff can edit it independently of
            // the document name. The legacy "Section · Name" label format is
            // still understood downstream for visas saved before this split.
            'category' => $section,
            'label' => $label,
            'hint' => $hint,
            'required' => $required,
        ];

        // Optional file-naming metadata. When `file_code` is set, uploads
        // against this item are renamed to "NN - CODE - FirstnameLASTNAME<suffix>"
        // (NN = the item's position). Left off entirely when not provided so
        // the other visa checklists stay unchanged.
        if ($fileCode !== null) {
            $entry['file_code'] = $fileCode;
        }
        if ($fileSuffix !== '') {
            $entry['file_suffix'] = $fileSuffix;
        }

        return $entry;
    }

    /**
     * Which template each seeded visa `code` maps to. The seeder still
     * targets these codes, but the templates themselves are also exposed
     * via templates() so the Visas edit screen can load any of them onto a
     * visa the user created by hand (any code) — no seeding required.
     */
    private function checklists(): array
    {
        $t = $this->templates();

        return [
            'WORK_AEWV' => $t['aewv']['items'],
            'STUDENT' => $t['student']['items'],
            'POST_STUDY_WORK' => $t['post_study_work']['items'],
            'PARTNER_WORK' => $t['partner_work']['items'],
            'PARTNER_RES' => $t['partner_res']['items'],
            'STRV' => $t['straight_to_resident']['items'],
            'WTRV' => $t['work_to_resident']['items'],
            'PRV' => $t['prv']['items'],
            'DCRV' => $t['dcrv']['items'],
            'SVDC' => $t['svdc']['items'],
            'VISITOR' => $t['visitor']['items'],
        ];
    }

    /**
     * Loadable checklist templates keyed by a stable slug, each with a
     * human label + its items. Reused by the seeder and surfaced to the
     * Visas edit screen's "Load a checklist template" picker.
     *
     * @return array<string, array{label: string, items: array}>
     */
    public function templates(): array
    {
        return [
            'student' => ['label' => 'Student Visa', 'items' => $this->studentVisa()],
            'partner_work' => ['label' => 'Partner of NZ — Work (Partnership)', 'items' => $this->partnershipVisa()],
            'partner_res' => ['label' => 'Partner of NZ — Resident', 'items' => $this->partnershipOfResident()],
            'aewv' => ['label' => 'Accredited Employer Work Visa (AEWV)', 'items' => $this->aewv()],
            'post_study_work' => ['label' => 'Post-Study Work Visa', 'items' => $this->postStudyWorkVisa()],
            'straight_to_resident' => ['label' => 'Straight to Residence', 'items' => $this->straightToResident()],
            'work_to_resident' => ['label' => 'Work to Residence', 'items' => $this->workToResident()],
            'prv' => ['label' => 'Permanent Resident Visa', 'items' => $this->permanentResidentVisa()],
            'dcrv' => ['label' => 'Dependent Child Resident Visa', 'items' => $this->dependentChildResidentVisa()],
            'svdc' => ['label' => 'Student Visa — Dependent Child', 'items' => $this->studentVisaDependentChild()],
            'visitor' => ['label' => 'General Visitor Visa', 'items' => $this->generalVisitorVisa()],
        ];
    }

    private function aewv(): array
    {
        return [
            $this->item('passport_pdf', 'Identity', 'Passport (PDF)',
                'Clear colour scan of your current, valid passport saved in PDF format.'),
            $this->item('passport_bio_page', 'Identity', 'Passport biographical page',
                'The photo/details page showing your name, date of birth, passport number and expiry date.'),
            $this->item('face_image_jpg', 'Identity', 'Face image (JPG)',
                'Recent passport-style photo – plain light background, full face, no glasses/hat – saved as a JPG file.'),

            $this->item('job_token', 'Employment', 'Job Token / Job Check copy',
                'Copy of the Job Check token issued to the employer, which links the approved role to your AEWV application.'),
            $this->item('offer_letter', 'Employment', 'Offer letter from employer',
                'Formal written job offer stating the role, pay rate, hours and key conditions.'),
            $this->item('current_aewv_copy', 'Employment', 'Current AEWV (work visa) copy',
                'Copy of your current Accredited Employer Work Visa showing its conditions and expiry date. If applicable.', false),
            $this->item('employer_accreditation', 'Employment', "Employer's active accreditation",
                "Evidence that the employer's INZ accreditation is current and active."),
            $this->item('employment_agreement', 'Employment', 'Signed employment agreement + job description',
                'Your signed employment agreement together with the position description matching the offer.'),
            $this->item('recent_payslips', 'Employment', 'Two recent payslips',
                'Your two most recent payslips as evidence of ongoing employment and pay.'),
            $this->item('cv', 'Employment', 'Updated CV',
                'Current curriculum vitae summarising your work history, roles and responsibilities.'),

            $this->item('medical_certificate', 'Health & Character', 'Updated medical / X-ray',
                'Chest X-ray and general medical certificate (generally required for stays over 12 months). A new certificate must be issued less than 3 months before you apply; you can re-use an earlier one only if it is less than 3 years old and was previously assessed as acceptable.'),
            $this->item('english_proof', 'Health & Character', 'English language proof',
                'IELTS 4.0 overall, PTE Academic 29, or an accepted equivalent (test less than 2 years old). From 1 June 2026 this requirement applies to ANZSCO/NOL skill level 3 roles too.'),
            $this->item('police_certificate', 'Health & Character', 'Police certificate (no criminal record)',
                'From 8 December 2025 a complete, valid certificate must be provided WITH your application – receipts are no longer accepted (except Fiji, Hong Kong, Israel).'),
            $this->item('ird_summary', 'Health & Character', 'IRD Summary of Earnings',
                'Inland Revenue earnings summary demonstrating at least 2 years of relevant work experience (reduced from 3 years on 10 March 2025).'),

            $this->item('inz_1225', 'Forms', 'INZ 1225 Work Visa Declaration Form',
                'Immigration NZ declaration form – complete and sign the 3 highlighted parts.'),
            $this->item('ep_service_agreement', 'Forms', 'EP service agreement',
                'Service agreement with the adviser/agency, fully completed and signed.'),
            $this->item('aewv_info_form', 'Forms', 'AEWV information form',
                'Internal AEWV information form, fully completed and signed.'),
        ];
    }

    private function studentVisa(): array
    {
        $applicant = 'Applicant Documents';
        $financial = 'Financial Documents (Applicant)';
        $sponsor = 'Sponsor Documents (if sponsored)';

        return [
            // ── 01–17 · Applicant Documents ──────────────────────────────
            $this->item('cv', $applicant, 'Curriculum Vitae',
                'Your current CV summarising education and work history.', true, 'CV'),
            $this->item('passport', $applicant, 'Passport (PDF format)',
                'Clear colour scan of your current, valid passport in PDF format.', true, 'PPT', '_EXPDDMMYYY'),
            $this->item('face_image', $applicant, 'Face image',
                'Recent passport-style photo — plain background, full face.', true, 'FI'),
            $this->item('birth_certificate', $applicant, 'Birth Certificate',
                'Your full birth certificate.', true, 'BC'),
            $this->item('marriage_certificate', $applicant, 'Marriage Certificate',
                'Official marriage certificate, if you are married. If applicable.', false, 'MC'),
            $this->item('graduate_certificate', $applicant, 'Graduate Certificate',
                'Your completed qualification certificate (diploma / degree).', true, 'CDPMA'),
            $this->item('transcript', $applicant, 'Transcript of Record',
                'Official academic transcript from your previous school.', true, 'TOR'),
            $this->item('pte_ielts_result', $applicant, 'PTE / IELTS Result',
                'Your English test result (PTE Academic, IELTS, or accepted equivalent).', true, 'PTE'),
            $this->item('certificate_of_employment', $financial, 'Certificate of Employment',
                'Certificate confirming your employment history, role and dates.', true, 'COE'),
            $this->item('offer_of_place', $applicant, 'Offer of Place',
                'Offer of Place from your NZ institution.', true, 'OOP'),
            $this->item('tuition_payment_evidence', $applicant, 'Evidence of Payment for Tuition Fee',
                'Official receipt from the school confirming tuition fees paid.', true, 'EOP'),
            $this->item('statement_of_purpose', $applicant, 'Statement of Purpose',
                'Personal statement about your study plan in NZ and your plans after study.', true, 'SOP'),
            $this->item('nbi_police_clearance', $applicant, 'NBI / Police Clearance Certificate',
                "Police clearance certificate (NBI for the Philippines, or your country's equivalent).", true, 'NBI'),
            $this->item('medical_certificate', $applicant, 'Medical Certificate (General and X-Ray)',
                'General medical and chest X-ray certificate from an INZ-approved panel clinic.', true, 'eMED'),
            $this->item('travel_records', $applicant, 'Visas / travel records (other countries)',
                'Copies of visas or travel records if you have been to other countries. If applicable.', false, 'TRAVEL'),
            $this->item('nz_visa', $applicant, 'NZ Visa (if onshore)',
                'Copy of your current NZ visa, if you are already in New Zealand. If applicable.', false, 'NZVISA'),
            $this->item('inz_1226', $applicant, 'Signed INZ1226 – Student Declaration Form',
                'Student Declaration Form, completed and signed.', true, 'INZ1226'),

            // ── 18–22 · Financial Documents (Applicant) ──────────────────
            $this->item('bank_statement', $financial, 'Bank Statement (at least 6 months)',
                'Your bank statements covering at least the last 6 months.', true, 'BNKS'),
            $this->item('payslips', $financial, 'Payslips (at least 6 months)',
                'Your payslips covering at least the last 6 months, if employed.', true, 'PYSLPS'),
            $this->item('business_permit', $financial, 'Business Permit',
                'Your business permit, if self-employed / a business owner. If applicable.', false, 'BUS'),
            $this->item('property_ownership', $financial, 'Property Ownership',
                'Evidence of any property you own (ties to home country). If applicable.', false, 'PROP'),
            $this->item('additional_funds', $financial, 'Additional source of funds',
                'Proof of any additional funds you wish to include to support the above savings. If applicable.', false, 'SOF'),

            // ── 23–32 · Sponsor Documents (if sponsored) ─────────────────
            $this->item('inz_1014', $sponsor, 'Signed INZ1014 – Financial Undertaking (Sponsorship)',
                'Financial Undertaking for a Student (Sponsorship) form, completed and signed.', false, 'INZ1014'),
            $this->item('sponsor_bank_statement', $sponsor, 'Bank Statement of Sponsor',
                'Recent bank statements for the sponsor showing sufficient funds.', false, 'BNKS', '_Sponsor'),
            $this->item('sponsor_letter_support', $sponsor, 'Letter of Support from family and friends',
                'Signed letter from the sponsor confirming they will support your study and living costs.', false, 'LOS', '_Sponsor'),
            $this->item('sponsor_photos', $sponsor, 'Photos with Sponsor',
                'Photos of you together with the sponsor, evidencing the relationship.', false, 'PHTS', '_Sponsor'),
            $this->item('sponsor_passport', $sponsor, 'Passport of Sponsor',
                "Copy of the sponsor's passport or photo ID.", false, 'PPT', '(of sponsor)'),
            $this->item('sponsor_birth_certificate', $sponsor, 'Birth Certificate – Sponsor',
                "Sponsor's birth certificate, to help establish the relationship.", false, 'BC', '(of sponsor)'),
            $this->item('sponsor_business_permit', $sponsor, 'Business permit',
                "Sponsor's business permit, if self-employed / a business owner. If applicable.", false, 'BUS', '(of sponsor)'),
            $this->item('sponsor_payslip', $sponsor, 'Payslip of Sponsor',
                "Sponsor's recent payslips, if employed. If applicable.", false, 'PYSLP', '(of sponsor)'),
            $this->item('sponsor_coe', $sponsor, 'Certificate of Employment of Sponsor',
                "Certificate confirming the sponsor's employment, role and income. If applicable.", false, 'COE', '(of sponsor)'),
            $this->item('sponsor_relationship_docs', $sponsor, 'Government-issued relationship / citizenship / marriage certificates',
                'Government-issued documents linking you to the sponsor.', false, 'ID', '(of sponsor)'),
        ];
    }

    private function postStudyWorkVisa(): array
    {
        return [
            $this->item('passport_pdf', 'Identity', 'Passport (PDF)',
                'Clear colour scan of your current, valid passport in PDF format.'),
            $this->item('passport_bio_page', 'Identity', 'Passport biographical page',
                'The photo/details page showing name, date of birth, passport number and expiry date.'),
            $this->item('face_image_jpg', 'Identity', 'Face image (JPG)',
                'Portrait JPG photo: 900×1200 to 2250×3000 pixels, file size 500 KB–3 MB, plain background, full face.'),

            $this->item('letter_of_eligibility', 'Study & Qualifications', 'Letter of Eligibility',
                'Letter confirming you are eligible for the post-study work visa based on your qualification.'),
            $this->item('degree', 'Study & Qualifications', 'Degree / Graduate Certificate',
                'Your completed NZ qualification certificate (degree or graduate certificate).'),
            $this->item('transcript', 'Study & Qualifications', 'Academic records / Transcript (TOR)',
                'Official transcript of records / academic results from your NZ programme.'),
            $this->item('student_visa_copy', 'Study & Qualifications', 'Student visa copy',
                'Copy of the student visa you held while completing the qualification.'),
            $this->item('attendance_report', 'Study & Qualifications', 'Attendance report (NZ school)',
                'Attendance record from your NZ education provider.'),

            $this->item('bank_statement', 'Financial', 'Bank statement (min NZD 5,000)',
                'Recent bank statement (ideally last 6 months) showing at least NZD 5,000 in funds.'),

            $this->item('medical_certificate', 'Health & Character', 'Medical certificate (general & X-ray)',
                'General medical and chest X-ray certificate from an Immigration NZ-approved panel clinic.'),
            $this->item('police_clearance', 'Health & Character', 'NBI / PCC clearance',
                'Police clearance certificate. Required if you are 17 or older and have been in NZ for 24 months or longer.'),

            $this->item('birth_certificate', 'Supporting Documents', 'Birth certificate',
                'Your full birth certificate.'),
            $this->item('travel_records', 'Supporting Documents', 'Visas / travel records',
                'Copies of visas or travel records if you have been to other countries. If applicable.', false),
            $this->item('tenancy_contract', 'Supporting Documents', 'Tenancy contract',
                'Your current tenancy agreement or proof of NZ address.'),
            $this->item('marriage_certificate', 'Supporting Documents', 'Marriage certificate',
                'Official marriage certificate, if you are married. If applicable.', false),

            $this->item('inz_1225', 'Forms', 'INZ 1225 Work Visa Declaration Form (signed)',
                'Immigration NZ work visa declaration form, completed and signed.'),
        ];
    }

    private function partnershipVisa(): array
    {
        $main = 'Applicant & Sponsorship Documents';
        $evidence = 'Genuine & Stable Partnership Evidence';

        return [
            $this->item('sponsor_passport', 'Sponsor Documents', 'Passport of Sponsor',
                "Copy of the supporting partner's passport.", true, 'PPT', '(of sponsor)'),
            // ── 01–09 · Applicant & Sponsorship Documents ────────────────
            $this->item('visa_information_form', $main, 'Visa Information Form',
                'The completed and signed Immigration NZ family/partner information form.', true, 'VIF'),
            $this->item('passport_bio_page', $main, 'Passport biographical page',
                'The photo/details page showing name, date of birth, passport number and expiry date.', true, 'PPT', '_EXPDDMMYYY'),
            $this->item('face_image', $main, 'Passport-style face image (JPG format)',
                'Recent passport-style photo — plain background, full face — saved as a JPG file.', true, 'FI'),
            $this->item('birth_certificate', $main, 'Birth Certificate',
                'Your full birth certificate.', true, 'BC'),
            $this->item('police_nbi_clearance', $main, 'Police / NBI Clearance (recent)',
                'A recent police/character certificate (NBI clearance for the Philippines).', true, 'NBI', '_ISSUEDDMMYYY'),
            $this->item('sponsor_nz_visa', $main, 'Copy of the most recent NZ visa of the Sponsor',
                "Copy of the supporting partner's current NZ visa, residence or citizenship status.", true, 'VISA', '_ISSUEDDMMYYY'),
            $this->item('medical_certificate', $main, 'E-medicals (X-ray and General Medical)',
                'From an INZ-approved clinic. Required if your previous medical has expired (over 3 years old) or was never provided.', false, 'eMED', '_ISSUEDDMMYYY'),
            $this->item('inz_1241', $main, 'INZ1241 Declaration Form for partner',
                'Partnership declaration form completed and signed by the applicant partner.', true, 'INZ1241'),
            $this->item('inz_1146', $main, 'INZ1146 Partner Supporting Form',
                'Supporting partnership form completed and signed by the supporting/sponsoring partner.', true, 'INZ1146'),

            // ── 10–21 · Genuine & Stable Partnership — supporting evidence.
            // Provide as much as you can (ideally the last 3 months); you
            // don't have to prepare every item — all optional.
            $this->item('marriage_certificate', $evidence, 'Marriage Certificate (if applicable)',
                'Official marriage or civil union certificate, if you are married.', false, 'MC'),
            $this->item('children_birth_certificate', $evidence, 'Birth Certificate of Children (if applicable)',
                'Birth certificate of any child you share, naming both partners as parents.', false, 'BC', ' of child'),
            $this->item('relationship_timeline', $evidence, 'Love story / relationship timeline between the couple',
                'A written account of how the relationship began and developed, with key dates.', false, 'LOVE'),
            $this->item('letter_of_support', $evidence, 'Letter of Support from both parents and friends',
                "Signed letters confirming the relationship, each with the writer's name and contact details.", false, 'LoF', '_Name of Family Member or Friend'),
            $this->item('financial_interdependence', $evidence, 'Financial interdependence between the couple',
                'Bank statements showing money transfers between the two of you, shared accounts or shared expenses.', false, 'FIN'),
            $this->item('communication_records', $evidence, 'Communication Records (WhatsApp, Viber, email)',
                'Message history across platforms showing ongoing contact.', false, 'COMREC'),
            $this->item('couple_photos', $evidence, 'Photos as a couple with family, friends & colleagues (6–8)',
                'A selection of photos together over time.', false, 'PHOTOS'),
            $this->item('joint_assets', $evidence, 'Joint Assets held under joint names',
                'Vehicle, house, financial investment or insurance in both names.', false, 'JA'),
            $this->item('travel_together', $evidence, 'Evidence of travelling together',
                'Shared room bookings, flight/transport tickets or itineraries.', false, 'TRAVEL'),
            $this->item('gifts_cards', $evidence, 'Photos of gifts and birthday cards sent to each other',
                'Photos of gifts and cards exchanged between you.', false, 'GIFTS'),
            $this->item('shared_address_bills', $evidence, 'Bills and mail sent to the same living address',
                'Utility bills, bank statements or dated online purchases addressed to each of you at the shared address.', false, 'BILLS'),
            $this->item('house_tenancy', $evidence, 'House ownership documents or tenancy agreement',
                'Proof of your shared home — ownership/title document or a current tenancy agreement.', false, 'HOUSE'),
        ];
    }

    private function partnershipOfResident(): array
    {
        return [
            $this->item('sponsor_passport', 'Sponsor Documents', 'Passport of Sponsor',
                "Copy of the supporting partner's passport.", true, 'PPT', '(of sponsor)'),
            $this->item('passport_pdf', 'Identity', 'Passport (PDF)',
                'Clear colour scan of your current, valid passport in PDF format.'),
            $this->item('passport_bio_page', 'Identity', 'Passport biographical page',
                'The photo/details page showing name, date of birth, passport number and expiry date.'),
            $this->item('face_image_jpg', 'Identity', 'Face image (JPG)',
                'Recent passport-style photo – plain light background, full face – saved as a JPG file.'),

            $this->item('medical_certificate', 'Health, Character & Sponsorship', 'E-medicals (X-ray & general medical)',
                'For residence it must be issued less than 3 months before lodging. Required if your previous medical has expired (over 3 years old) or was never provided.'),
            $this->item('police_clearance', 'Health, Character & Sponsorship', 'Police clearance',
                'A recent police/character certificate from each country you have lived in long-term. From 8 December 2025 a complete certificate is required — receipts no longer accepted (except Fiji, Hong Kong, Israel).'),
            $this->item('family_visa_info_form', 'Health, Character & Sponsorship', 'Family Visa Information Form',
                'The family/partner information form, completed and signed by the supporting (resident) partner.'),
            $this->item('sponsor_visa', 'Health, Character & Sponsorship', "Sponsor's most recent NZ visa",
                "Copy of the supporting partner's current NZ resident visa or citizenship evidence."),

            $this->item('marriage_certificate', 'Partnership Evidence', 'Marriage certificate',
                'Official marriage or civil union certificate, if you are married. If applicable.', false),
            $this->item('children_birth_certificate', 'Partnership Evidence', "Children's birth certificate",
                'Birth certificate of any child you share. If applicable.', false),
            $this->item('relationship_timeline', 'Partnership Evidence', 'Relationship timeline / love story',
                'A written account of how the relationship began and developed, with key dates.'),
            $this->item('supporting_letters', 'Partnership Evidence', 'Supporting letters from parents & friends',
                "Signed letters confirming the relationship, each with the writer's name and contact details."),
            $this->item('financial_interdependence', 'Partnership Evidence', 'Financial interdependence',
                'Bank statements showing money transfers between the two of you, shared accounts or shared expenses.'),
            $this->item('call_logs', 'Partnership Evidence', 'Call logs',
                'Records of phone calls between the couple. If applicable.', false),
            $this->item('communication_records', 'Partnership Evidence', 'Communication records',
                'Message history showing ongoing contact.'),
            $this->item('couple_photos', 'Partnership Evidence', 'Couple photos (6–8)',
                'A selection of photos together over time.'),
            $this->item('joint_assets_docs', 'Partnership Evidence', 'Joint assets documents',
                'Documents for any assets you own together. If applicable.', false),
            $this->item('joint_assets_names', 'Partnership Evidence', 'Assets in joint names',
                'Vehicle, house, financial investments or insurance in both names. If applicable.', false),
            $this->item('travel_together', 'Partnership Evidence', 'Evidence of travelling together',
                'Shared room bookings, flight/transport tickets or itineraries. If applicable.', false),
            $this->item('gifts_cards', 'Partnership Evidence', 'Photos of gifts & cards',
                'Photos of gifts and cards exchanged. If applicable.', false),
            $this->item('shared_address_bills', 'Partnership Evidence', 'Bills & mail to the same address',
                'Utility bills, bank statements or dated mail addressed to each of you at the shared address.'),
            $this->item('house_tenancy', 'Partnership Evidence', 'House ownership or tenancy agreement',
                'Proof of your shared home – ownership/title document or a current tenancy agreement.'),
        ];
    }

    private function straightToResident(): array
    {
        return [
            $this->item('passport_pdf', 'Identity', 'Passport (PDF)',
                'Clear colour scan of your current, valid passport in PDF format.'),
            $this->item('passport_bio_page', 'Identity', 'Passport biographical page',
                'The photo/details page showing name, date of birth, passport number and expiry date.'),
            $this->item('face_image_jpg', 'Identity', 'Face image (JPG)',
                'Recent passport-style photo – plain light background, full face.'),
            $this->item('current_visa', 'Identity', 'Current visa copy',
                'Copy of your current NZ visa, if you hold one. If applicable.', false),

            $this->item('graduate_certificate', 'Qualifications', 'Graduate certificate',
                'Your completed qualification certificate.'),
            $this->item('transcript', 'Qualifications', 'Transcript of record (TOR)',
                'Official academic transcript of your results.'),
            $this->item('nzqa_assessment', 'Qualifications', 'NZQA assessment',
                'NZQA International Qualifications Assessment of your overseas qualification, if required. If applicable.', false),
            $this->item('nz_registration', 'Qualifications', 'NZ registration of professional degree',
                'Evidence of NZ professional registration for your occupation, if applicable. If applicable.', false),
            $this->item('cv', 'Qualifications', 'Updated CV',
                'Current CV summarising your work history and responsibilities.'),

            $this->item('offer_letter', 'Employment', 'Offer letter from employer',
                'Formal written job offer stating the role, pay and conditions.'),
            $this->item('employment_agreement', 'Employment', 'Signed employment agreement + job description',
                'Your signed employment agreement with the matching position description.'),
            $this->item('coe', 'Employment', 'Certificate of Employment (COE)',
                'Certificate from your employer confirming your role, tenure and duties.'),
            $this->item('payslips', 'Employment', 'Payslips',
                'Recent payslips evidencing your employment and pay.'),
            $this->item('ird_summary', 'Employment', 'IRD Summary of Earnings',
                'Inland Revenue earnings summary supporting your work history.'),

            $this->item('pte', 'English & Health', 'PTE Academic (58 average)',
                'English test result – PTE Academic with an average total band of 58 (or accepted equivalent). Verified June 2026.'),
            $this->item('medical_certificate', 'English & Health', 'Updated medical (X-ray & general)',
                'General medical and chest X-ray. For residence it must be issued less than 3 months before lodging.'),
            $this->item('police_clearance', 'English & Health', 'NBI certificate / PCC',
                'Police clearance certificate. From 8 December 2025 complete certificate required, not receipts.'),

            $this->item('inz_1242', 'Forms', 'Signed INZ 1242',
                'Immigration NZ residence declaration form, completed and signed.'),
            $this->item('ep_service_agreement', 'Forms', 'EP service agreement',
                'Service agreement with the adviser/agency, completed and signed.'),
            $this->item('info_form', 'Forms', 'Information form',
                'Internal information form, completed and signed.'),
        ];
    }

    private function workToResident(): array
    {
        return [
            $this->item('passport_pdf', 'Identity', 'Passport (PDF)',
                'Clear colour scan of your current, valid passport in PDF format.'),
            $this->item('passport_bio_page', 'Identity', 'Passport biographical page',
                'The photo/details page showing name, date of birth, passport number and expiry date.'),
            $this->item('face_image_jpg', 'Identity', 'Face image (JPG)',
                'Recent passport-style photo – plain light background, full face.'),
            $this->item('current_work_visa', 'Identity', 'Copy of current work visa',
                'Copy of your current NZ work visa, if you hold one. If applicable.', false),

            $this->item('current_payslip', 'Employment', 'Current payslip',
                'Your most recent payslip evidencing ongoing employment and pay.'),
            $this->item('offer_letter', 'Employment', 'Offer letter from employer',
                'Formal written job offer stating the role, pay and conditions.'),
            $this->item('employment_agreement', 'Employment', 'Signed employment agreement + job description',
                'Your signed employment agreement with the matching position description.'),
            $this->item('coe', 'Employment', 'Certificate of Employment (COE)',
                'Certificate from your employer confirming your role, tenure and duties.'),
            $this->item('cv', 'Employment', 'Updated CV',
                'Current CV summarising your work history and responsibilities.'),
            $this->item('ird_summary_2yr', 'Employment', 'IRD Summary of Earnings (2 years)',
                'Inland Revenue earnings summary evidencing at least 2 years of relevant work experience.'),

            $this->item('nzqa_assessment', 'Qualifications', 'NZQA assessment',
                'NZQA International Qualifications Assessment of your overseas qualification, if required. If applicable.', false),
            $this->item('nz_registration', 'Qualifications', 'NZ registration of professional degree',
                'Evidence of NZ professional registration for your occupation, if applicable. If applicable.', false),

            $this->item('pte', 'English & Health', 'PTE Academic (58 average)',
                'English test result – PTE Academic 58 average or accepted equivalent. Verified June 2026.'),
            $this->item('medical_certificate', 'English & Health', 'Updated medical (X-ray & general)',
                'General medical and chest X-ray. For residence it must be issued less than 3 months before lodging.'),
            $this->item('police_clearance', 'English & Health', 'PCC / NBI certificate',
                'Police clearance certificate. From 8 December 2025 complete certificate required.'),

            $this->item('inz_1242', 'Forms', 'Signed INZ 1242',
                'Immigration NZ residence declaration form, completed and signed.'),
            $this->item('ep_service_agreement', 'Forms', 'EP service agreement',
                'Service agreement with the adviser/agency, completed and signed.'),
            $this->item('info_form', 'Forms', 'Information form',
                'Internal information form, completed and signed.'),
        ];
    }

    private function permanentResidentVisa(): array
    {
        return [
            $this->item('passport_pdf', 'Identity & Forms', 'Passport (PDF)',
                'Clear colour scan of your current, valid passport in PDF format.'),
            $this->item('passport_bio_page', 'Identity & Forms', 'Passport biographical page',
                'The photo/details page showing name, date of birth, passport number and expiry date.'),
            $this->item('face_image_jpg', 'Identity & Forms', 'Face image (JPG)',
                'Recent passport-style photo – plain light background, full face.'),
            $this->item('resident_visa_copy', 'Identity & Forms', 'Copy of resident visa',
                'Copy of your current NZ resident visa.'),
            $this->item('inz_1242', 'Identity & Forms', 'Signed INZ 1242 declaration form',
                'Two parts require signature: husband (yellow highlight), wife (blue highlight).'),
            $this->item('family_visa_info_form', 'Identity & Forms', 'Family Visa Information Form',
                'The completed and signed Immigration NZ family information form.'),

            $this->item('shared_address_bills', 'Proof of Living', 'Bills & mail to the same address',
                'Utility bills, bank statements or dated online purchases showing your name at your current address.'),
            $this->item('house_tenancy', 'Proof of Living', 'House ownership or tenancy agreement',
                'Proof of your home – ownership/title document or a current tenancy agreement.'),
        ];
    }

    private function dependentChildResidentVisa(): array
    {
        return [
            $this->item('passport_pdf', 'Identity', 'Passport (PDF)',
                "Clear colour scan of the child's current, valid passport saved in PDF format."),
            $this->item('passport_bio_page', 'Identity', 'Passport biographical page',
                "The photo/details page showing the child's name, date of birth, passport number and expiry date."),
            $this->item('face_image_jpg', 'Identity', 'Face image (JPG)',
                'Recent passport-style photo – plain light background, full face, no glasses/hat – saved as a JPG file.'),

            $this->item('birth_certificate', 'Relationship & Sponsorship', 'Birth certificate',
                "Full birth certificate naming both parents, to prove the child's relationship to the sponsoring parent."),
            $this->item('sponsor_nz_visa', 'Relationship & Sponsorship', "Sponsor's most recent NZ visa",
                "Copy of the sponsoring parent's current NZ resident/citizen status or most recent NZ visa."),
            $this->item('applicant_nz_visa', 'Relationship & Sponsorship', "Applicant's current NZ visa",
                "Copy of the child's existing NZ visa, if they currently hold one. If applicable.", false),
            $this->item('family_visa_info_form', 'Relationship & Sponsorship', 'Family Visa Information Form',
                'The completed and signed Immigration NZ family/dependent information form.'),

            $this->item('medical_certificate', 'Health & Character', 'E-medicals (X-ray & general medical)',
                'Completed at an INZ-approved panel clinic. For residence it must be issued less than 3 months before lodging.'),
            $this->item('police_clearance', 'Health & Character', 'NBI / police clearance (if 18+)',
                'A recent police/character certificate, required if the applicant is 18 years or older. Required if applicable.', false),

            $this->item('shared_address_bills', 'Proof of Living', 'Bills & mail to the same address',
                "Utility bills, bank statements or dated online purchases showing the applicant's name at the shared living address."),
            $this->item('house_tenancy', 'Proof of Living', 'House ownership or tenancy agreement',
                'Proof of the home – e.g. ownership/title document or a current tenancy agreement.'),
        ];
    }

    private function studentVisaDependentChild(): array
    {
        $identity = 'Identity & Photo';
        $rel = 'Relationship & Sponsorship';
        $health = 'Health & Character';
        $living = 'Proof of Living Situation';

        return [
            // ── Identity & Photo ─────────────────────────────────────────
            $this->item('passport', $identity, 'Passport (PDF)',
                "Clear colour scan of the child's current, valid passport in PDF format.", true, 'PPT'),
            $this->item('passport_bio_page', $identity, 'Passport biographical page',
                "The photo/details page showing the child's name, date of birth, passport number and expiry.", true, 'PPTBIO'),
            $this->item('face_image', $identity, 'Face image (JPG)',
                'Recent passport-style photo — plain light background, full face — saved as a JPG file.', true, 'FI'),

            // ── Relationship & Sponsorship ───────────────────────────────
            $this->item('birth_certificate', $rel, 'Birth certificate',
                "Full birth certificate naming both parents, to prove the child's relationship to the sponsoring parent.", true, 'BC', '(of child)'),
            $this->item('sponsor_sv_visa', $rel, "Sponsor's most recent NZ visa (SV)",
                "Copy of the sponsoring parent's current NZ Student Visa.", true, 'SV', '(of sponsor)'),
            $this->item('applicant_nz_visa', $rel, "Applicant's current NZ visa (if held)",
                "Copy of the child's existing NZ visa, if they currently hold one. If applicable.", false, 'NZVISA'),
            $this->item('family_visa_info_form', $rel, 'Family Visa Information Form',
                'The completed and signed Immigration NZ family/dependent information form.', true, 'FVIF'),
            $this->item('inz_1241', $rel, 'Signed INZ 1241 (by parent)',
                'Declaration form signed by the parent in support of the dependent child.', true, 'INZ1241', '(by parent)'),

            // ── Health & Character ───────────────────────────────────────
            $this->item('medical_certificate', $health, 'E-medicals (X-ray & general medical)',
                'Completed at an INZ-approved panel clinic. Required if the previous medical has expired (over 3 years old) or was never provided.', false, 'eMED'),
            $this->item('nbi_police_clearance', $health, 'NBI clearance (if 18+)',
                'A recent police/character certificate (NBI for the Philippines), required if the applicant is 18 years or older.', false, 'NBI'),

            // ── Proof of Living Situation ────────────────────────────────
            $this->item('shared_address_bills', $living, 'Bills & mail to the same address',
                "Utility bills, bank statements or dated online purchases showing the applicant's name at the shared address.", true, 'BILLS'),
            $this->item('house_tenancy', $living, 'House ownership or tenancy agreement',
                'Proof of the home — ownership/title document or a current tenancy agreement.', true, 'HOUSE'),
        ];
    }

    private function generalVisitorVisa(): array
    {
        $identity = 'Identity & Forms';
        $support = 'Sponsorship & Support (if applicable)';
        $financial = 'Financial & Ties';
        $employment = 'Employment (if applicable)';

        return [
            // ── Identity & Forms ─────────────────────────────────────────
            $this->item('passport', $identity, 'Passport (PDF)',
                'Clear colour scan of your current, valid passport in PDF format.', true, 'PPT'),
            $this->item('passport_bio_page', $identity, 'Passport biographical page',
                'The photo/details page showing your name, date of birth, passport number and expiry.', true, 'PPTBIO'),
            $this->item('face_image', $identity, 'Face image (JPG, 35×45mm)',
                'Recent passport-style photo — 35mm wide × 45mm high, plain background, full face — saved as a JPG file.', true, 'FI'),
            $this->item('inz_1224', $identity, 'Signed INZ1224 Declaration Form',
                'Visitor Visa declaration form, completed and signed.', true, 'INZ1224'),
            $this->item('current_nz_visa', $identity, 'Copy of most recent NZ visa (if applicable)',
                'Copy of your most recent NZ visa, if you have held one. If applicable.', false, 'NZVISA'),
            $this->item('police_clearance', $identity, 'Police Clearance (if over 2 years old)',
                'Updated police clearance if your previous one is more than 2 years old. Can be provided later or when INZ requests it.', false, 'PCC'),

            // ── Sponsorship & Support (if applicable) ────────────────────
            $this->item('inz_1256', $support, 'Signed INZ1256 Sponsorship Form',
                'Sponsorship form signed by the NZ supporting person. Required only if sponsored.', false, 'INZ1256', '(NZ supporter)'),
            $this->item('relationship_certificate', $support, 'Relationship certificate (NZ supporter ↔ applicant)',
                'Birth, kinship or family register evidencing the relationship between the NZ supporting person and the applicant. If applicable.', false, 'RC'),
            $this->item('sponsor_passport_visa', $support, "NZ supporting person's passport & resident visa",
                "Copy of the NZ supporting person's passport and resident visa. If applicable.", false, 'SPV', '(NZ supporter)'),

            // ── Financial & Ties ─────────────────────────────────────────
            $this->item('bank_savings', $financial, 'Bank savings (NZ$1,000 per month of stay)',
                "At least NZ\$1,000 in savings for each month of stay if you don't have accommodation.", false, 'BNKSAV'),
            $this->item('bank_statement', $financial, "Last 6 months' bank statement",
                'Bank statements for the last 6 months showing income from work. If applicable (e.g. PHP 150,000).', false, 'BNKS'),
            $this->item('ties_evidence', $financial, 'Evidence of ties to home country',
                'Family relationship / birth certificates with parents and children, property ownership, savings, updated CV and qualification / occupational certificates.', true, 'TIES'),
            $this->item('house_ownership', $financial, 'House ownership',
                'Evidence of any property you own in your home country. If applicable.', false, 'HOUSE'),
            $this->item('marriage_certificate', $financial, 'Marriage certificate (if applicable)',
                'Official marriage certificate, if you are married. If applicable.', false, 'MC'),
            $this->item('travel_plan', $financial, 'Travel plan / itinerary',
                'A brief travel plan or itinerary — dates, main activities, purpose of travel and place of stay.', true, 'TRAVEL'),

            // ── Employment (if applicable) ───────────────────────────────
            $this->item('leave_permit', $employment, 'Leave permit from current workplace',
                'Approved leave permit from your current employer. If applicable.', false, 'LEAVE'),
            $this->item('payslips', $employment, 'Most recent payslips (1 month)',
                'Your most recent one month of payslips. If applicable.', false, 'PYSLP'),
            $this->item('employer_correspondence', $employment, 'Evidence of communication with potential employer',
                'Correspondence showing a job check preparation / application is in progress. If applicable.', false, 'EMPCOR'),
        ];
    }
}
