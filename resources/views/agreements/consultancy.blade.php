{{--
    Consultancy Agreement — 4-scenario template.
    Rendered to PDF by AgreementGenerator::consultancy() via barryvdh/laravel-dompdf.

    Vars expected:
        $scenario                 ('std_150' | 'voucher_150' | 'std_100' | 'english_100')
        $scenario_number          (1 | 2 | 3)
        $scenario_title           ('STANDARD 150,000' | 'WITH VOUCHER 150,000' | 'STANDARD' | 'WITH ENGLISH')
        $scenario_applicant       ('MAIN APPLICANT (Single and Couple)' | 'MAIN APPLICANT (Single)')
        $scenario_description     (paragraph text under the applicant line)
        $scenario_has_voucher     (bool — true only for WITH VOUCHER 150,000, adds the crossed-out Immigration NZ Fee line)
        $school_enrolment_fee     (integer, e.g. 150000 or 100000 — the headline fee for the chosen scenario)
        $english_proficiency_fee  (integer, e.g. 14500 — English review / exam fee shown in the cost breakdown)
        $inz_voucher_fee          (integer, e.g. 30600 — shown as strike-through under the fee cell in the voucher scenario)
        $is_couple_scenario       (bool — controls whether Section 2 renders the Couple cost breakdown too)
        $client_name              (lead's full name in UPPERCASE for the intro paragraph)
        $client_reference         (slugified name for bank Reference)
        $generated_at_formatted   ('6th of July 2026')
--}}
@php
    $fmt = fn ($n) => 'PhP ' . number_format((float) $n, 2);
    $fmt0 = fn ($n) => 'Php ' . number_format((float) $n, 0);

    // Currency-aware formatters for the STAFF-ENTERED fees (School Enrolment
    // + English Proficiency). These follow the chosen currency symbol; the
    // static reference breakdown below keeps its canonical PHP estimates.
    $curSym = $currency_symbol ?? 'Php';
    $fmtFee  = fn ($n) => $curSym . ' ' . number_format((float) $n, 2);
    $fmtFee0 = fn ($n) => $curSym . ' ' . number_format((float) $n, 0);

    // The live-preview endpoint renders this same view straight to HTML.
    // There the running header/footer must sit in normal flow — the fixed
    // negative offsets that make dompdf repeat them per page would push
    // them off-screen in a browser.
    $preview = $preview ?? false;

    // dompdf reads assets off disk; the browser needs URLs. Without this
    // the preview silently falls back to a generic sans, which is why it
    // never looked like the PDF.
    $logoSrc = $preview
        ? asset('images/ep-logo.png')
        : public_path('images/ep-logo.png');
    $font = fn ($file) => $preview
        ? asset("fonts/urbanist/{$file}")
        : public_path("fonts/urbanist/{$file}");
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Consultancy Agreement — {{ $client_name }}</title>
<style>
    /* Urbanist — published to public/fonts/urbanist so both dompdf (disk
       path) and the browser preview (URL) can resolve the same files. */
    @font-face { font-family: 'Urbanist'; font-style: normal; font-weight: 400; src: url('{{ $font("Urbanist-Regular.ttf") }}') format('truetype'); }
    @font-face { font-family: 'Urbanist'; font-style: italic; font-weight: 400; src: url('{{ $font("Urbanist-Italic.ttf") }}') format('truetype'); }
    @font-face { font-family: 'Urbanist'; font-style: normal; font-weight: 700; src: url('{{ $font("Urbanist-Bold.ttf") }}') format('truetype'); }
    @font-face { font-family: 'Urbanist'; font-style: italic; font-weight: 700; src: url('{{ $font("Urbanist-BoldItalic.ttf") }}') format('truetype'); }

    @page { margin: 144px 62px 75px 62px; }
    /* The browser preview has no page box, so the @page side margins above
       don't apply and content would sit flush against the panel edge. */
    body.preview { padding: 24px 62px; }
    /* 10pt / 1.42 matches the density of the approved Word original and
       lands the document on 9 pages; at 12pt it reflows onto 12. */
    body { font-family: 'Urbanist', DejaVu Sans, sans-serif; font-size: 10pt; color: #111; line-height: 1.42; }

    /* Screen-only preview measure. dompdf honours @page; browsers do
       not, so without this the iframe stretches body full-width and
       12pt lines get too long to read. Centering at 794px + matching
       55px horizontal padding replicates the printed A4 measure. */
    @media screen {
        body { max-width: 794px; margin: 0 auto; padding: 24px 55px; background: #fff; }
        .page-header { position: static; text-align: center; margin: -8px 0 12px 0; }
        .page-header img { height: 88px; }
    }

    /* Running header — dompdf repeats fixed-position elements on every
       page, so the logo shows at the top of pages 1 through 9. Height
       chosen to fit the logo comfortably; body @page margin above
       reserves the space so text doesn't run under it. */
    /* The logo file (ep-logo.png) is pre-trimmed of its transparent padding,
       so this height is all artwork — keep it that way if the image is ever
       re-exported. */
    .page-header { position: fixed; top: -116px; left: 0; right: 0; text-align: center;
                   border-bottom: 1.5px solid #436235; padding-bottom: 10px; }
    .page-header img { height: 100px; width: auto; }
    /* Preview-only: drop the logo into normal flow so it's actually visible
       in the browser, where the negative fixed offset would hide it. */
    .page-header.in-flow { position: static; margin-bottom: 16px; }

    /* Running footer rule — fixed position repeats it on every page. The
       text itself is drawn by the page_text() block at the end of <body>:
       dompdf supports counter(page) in CSS but NOT counter(pages), so a
       pure-CSS footer renders "Page 3 of 0". */
    .page-footer { position: fixed; bottom: -34px; left: 0; right: 0;
                   border-top: 1.5px solid #436235; }

    /* Explicit breaks so the PDF paginates like the approved Word original
       instead of wherever the text happens to reflow. */
    .page-break { page-break-before: always; }
    .no-break { page-break-inside: avoid; }
    .eyebrow { text-align: center; color: #436235; font-weight: bold; font-size: 9pt; letter-spacing: 2px; margin-bottom: 6px; }
    h1 { text-align: center; font-size: 22pt; font-weight: 900; letter-spacing: 1px; margin: 0 0 4px 0; color: #14213b; }
    .subtitle { text-align: center; color: #555; font-size: 10pt; font-style: italic; margin-bottom: 18px; }
    hr { border: 0; border-top: 1.5px solid #436235; margin: 14px 0; }
    .article-bar { text-align: center; font-weight: bold; color: #436235; font-size: 11pt; letter-spacing: 1.2px; padding: 5px 0; border-top: 1.5px solid #436235; border-bottom: 1.5px solid #436235; margin: 15px 0 11px 0; }
    .article-bar.thin { border-color: #436235; }
    h3 { color: #436235; font-size: 10.5pt; margin-top: 12px; margin-bottom: 5px; }
    p { margin: 7px 0; text-align: justify; }
    ul li { margin: 3px 0; }
    .strong { font-weight: bold; }
    .name-strong { font-weight: bold; color: #556B2F; }
    .company-name { font-weight: bold; font-style: italic; }

    /* Application Type table (page 1). */
    table.apptype { width: 100%; border-collapse: collapse; margin: 12px 0; }
    table.apptype thead th { background: #436235; color: #fff; text-align: center; padding: 6px 8px; font-size: 10pt; }
    table.apptype td { border: 1px solid #b7ceac; background: #f4f8f0; padding: 10px 12px; font-size: 10pt; vertical-align: top; }
    table.apptype td.check-cell { width: 44px; text-align: center; font-size: 14pt; background: #fbfdf9; }
    table.apptype td.fee-cell { width: 150px; text-align: left; font-weight: bold; color: #2e7d32; font-size: 11pt; }
    table.apptype.custom-description { border: 1px solid #b7ceac; }
    table.apptype.custom-description tbody td { border-top: 0; border-bottom: 0; padding-top: 0; padding-bottom: 0; }
    table.apptype.custom-description tr.application-summary td { padding-top: 10px; padding-bottom: 4px; }
    table.apptype.custom-description tbody tr:last-child td { padding-bottom: 10px; border-bottom: 1px solid #b7ceac; }
    .voucher-strike { text-decoration: line-through; color: #555; font-weight: normal; display: block; margin-top: 8px; font-size: 10pt; }
    .voucher-note { color: #436235; font-weight: bold; font-size: 9pt; margin-top: 6px; }

    /* Section 1 bank box (page 2). */
    .bank-box { border: 1px solid #436235; padding: 10px 14px; margin: 8px 0 16px 0; }
    .bank-box .heading { color: #436235; font-weight: bold; margin-bottom: 6px; }
    .bank-box .row { padding: 2px 0; font-size: 10pt; }
    .bank-box .label { display: inline-block; width: 130px; color: #555; }
    .bank-box .ref-red { color: #d0121a; font-weight: bold; }

    /* Cost breakdown tables (pages 3-4). */
    /* Kept dense on purpose — at looser padding the SINGLE breakdown spills
       past its page and pushes every later page out of alignment. */
    table.cost { width: 100%; border-collapse: collapse; margin: 8px 0 4px 0; line-height: 1.3; }
    table.cost th { background: #436235; color: #fff; text-align: left; padding: 4px 8px; font-size: 9.5pt; }
    table.cost th.right { text-align: right; }
    table.cost td { border: 1px solid #d8d8d8; padding: 3px 8px; font-size: 9pt; vertical-align: top; }
    table.cost td.right { text-align: right; font-weight: bold; }
    table.cost tr.group td { background: #e8f0e2; color: #14213b; font-weight: bold; font-size: 9.5pt; border-color: #b7ceac; }
    table.cost .muted { color: #666; font-style: italic; font-size: 8pt; }
    table.cost tr.total td { background: #14213b; color: #fff; font-weight: bold; }
    .breakdown-caption { color: #436235; font-weight: bold; font-size: 9.5pt; margin-top: 10px; margin-bottom: 3px; letter-spacing: 0.4px; }

    ul { margin: 6px 0 10px 20px; padding: 0; }
    ul li { margin: 4px 0; font-size: 10pt; }

    /* Signature (page 9). */
    .signature-table { width: 100%; border-collapse: collapse; margin-top: 18px; }
    .signature-table th { background: #436235; color: #fff; text-align: center; padding: 6px; font-size: 10pt; }
    .signature-table td { border: 1px solid #436235; padding: 12px; vertical-align: top; font-size: 10pt; width: 50%; height: 70px; }
    .sig-name { font-weight: bold; text-align: center; margin-top: 22px; }
    .sig-role { text-align: center; font-style: italic; color: #555; font-size: 9pt; }
    .sig-meta-row td { padding: 6px 12px; height: auto; }
    .ack-box { border-left: 4px solid #436235; padding: 8px 12px; margin-top: 14px; font-weight: bold; font-size: 10pt; background: #f4f8f0; }
    .footer-rule { border-top: 1px solid #d8d8d8; margin-top: 24px; padding-top: 6px; font-size: 8pt; color: #888; text-align: center; }
</style>
</head>
<body class="{{ $preview ? 'preview' : '' }}">

<div class="page-header{{ $preview ? ' in-flow' : '' }}">
    <img src="{{ $logoSrc }}" alt="ePathways Philippines">
</div>

@unless ($preview)
<div class="page-footer"></div>
@endunless

<div class="eyebrow">OFFICIAL DOCUMENT &nbsp;•&nbsp; CONFIDENTIAL</div>
<h1>CONSULTANCY AGREEMENT</h1>
<div class="subtitle">Educational Pathway &amp; Visa Consultation Services</div>
<hr>

<p>This Engagement Agreement (&ldquo;Agreement&rdquo;) delineates the terms and conditions governing the provision of educational pathway and visa consultation services (through our Licensed Immigration Adviser Partner in New Zealand) by <span class="company-name">ePathways Philippines Consultancy</span> to <span class="name-strong">{{ mb_strtoupper($client_name ?: 'INSERT NAME HERE') }}</span>. It is imperative that you thoroughly review the ensuing terms and signify your acceptance by executing and furnishing a copy of this Agreement.</p>

<div class="article-bar">ARTICLE 1 &nbsp;•&nbsp; FEES STRUCTURE &amp; PAYMENT PLAN</div>

<p><em>Application Type</em> &mdash; please indicate your application type by clicking the box beside your chosen option. The applicable package fee is shown on the right.</p>

{{-- Application Type — every scenario is listed so the client sees the
     full menu, exactly as in the Word original. Only the scenario staff
     picked is ticked, and only that row's fee reflects the amount they
     edited on the "New" modal; the rest show their catalogue defaults.

     Only the selected scenario renders — staff picks it in the "New"
     modal, so the PDF doesn't need to enumerate the other three. --}}
@php
    $s = $scenarios[(string) $scenario] ?? $scenarios['std_100'];
@endphp

@if ($scenario_description_custom ?? false)
{{-- Dompdf paginates between rows, never inside a cell. Keep each explicit
     custom line in its own row so long descriptions cannot cross the footer. --}}
<table class="apptype custom-description">
    <thead><tr><th colspan="2">APPLICATION TYPE</th></tr></thead>
    <tbody>
        <tr class="application-summary">
            <td>
                <div class="strong">{{ $s['title'] }}</div>
                <div class="strong">{{ $scenario_applicant }}</div>
            </td>
            <td class="fee-cell">
                {{ $fmtFee0($school_enrolment_fee) }}
                @if ($s['has_voucher'])
                    <span class="voucher-strike">{{ $fmt($inz_voucher_fee) }}</span>
                @endif
            </td>
        </tr>
        @foreach (preg_split('/\r\n|\r|\n/', $scenario_description) as $line)
            <tr>
                <td style="word-wrap: break-word;">{{ $line === '' ? "\u{00A0}" : $line }}</td>
                <td class="fee-cell"></td>
            </tr>
        @endforeach
        @if ($s['has_voucher'])
            <tr>
                <td>
                    <div style="margin-top: 10px;"><strong>Immigration NZ Fee</strong></div>
                    <div class="voucher-note">Already covered within the School Enrolment and Documentation Fee</div>
                </td>
                <td class="fee-cell"></td>
            </tr>
        @endif
    </tbody>
</table>
@else
<table class="apptype no-break">
    <thead>
        <tr><th colspan="2">APPLICATION TYPE</th></tr>
    </thead>
    <tbody>
        <tr>
            <td>
                <div class="strong">{{ $s['title'] }}</div>
                <div class="strong">{{ $scenario_applicant }}</div>
                <div style="margin-top: 4px;">{!! $s['description'] !!}</div>
                @if ($s['has_voucher'])
                    <div style="margin-top: 10px;"><strong>Immigration NZ Fee</strong></div>
                    <div class="voucher-note">Already covered within the School Enrolment and Documentation Fee</div>
                @endif
            </td>
            <td class="fee-cell">
                {{ $fmtFee0($school_enrolment_fee) }}
                @if ($s['has_voucher'])
                    <span class="voucher-strike">{{ $fmt($inz_voucher_fee) }}</span>
                @endif
            </td>
        </tr>
    </tbody>
</table>
@endif

<p>It is explicitly acknowledged that the fees rendered for our services become <strong>non-refundable</strong> once work has commenced. Any refund requests will be assessed on an individual basis at the sole discretion of ePathways Philippines.</p>

@if ($scenario_description_custom ?? false)
<div class="no-break">
@endif
<h3>Section 1. Bank Details</h3>
<div class="bank-box">
    <div class="heading">{{ $bank_heading ?? 'Payment for School Enrollment and Documentation Fee' }}</div>
    <div class="row"><span class="label">Bank Name:</span> <strong>{{ $bank_name ?? 'RCBC' }}</strong></div>
    <div class="row"><span class="label">Account Name:</span> <strong>{{ $bank_account_name ?? 'Dinah Suarin' }}</strong></div>
    <div class="row"><span class="label">Account Number:</span> <strong>{{ $bank_account_number ?? '9045440503' }}</strong></div>
    <div class="row"><span class="label">Reference:</span> <span class="ref-red">#{{ $bank_reference ?? $client_reference }}</span></div>
</div>
@if ($scenario_description_custom ?? false)
</div>
@endif

{{-- ── Page 3 ───────────────────────────────────────────────── --}}
<div class="page-break"></div>

<div class="article-bar" style="margin-top:0;">REFERENCE &nbsp;•&nbsp; INDICATIVE COST BREAKDOWN</div>

<p><em>The table below is provided for reference only. It sets out the indicative end-to-end cost of a New Zealand student visa application. All amounts marked (approximate) are estimates only and may vary with exchange rates, third-party providers, and the chosen program. Disbursements such as tuition, airfare, and show money are the responsibility of the Client.</em></p>

{{-- Both breakdowns always render. They are reference-only tables from
     the Word original, so the client sees single and couple side by side
     regardless of which scenario was ticked. --}}
<div class="breakdown-caption">BREAKDOWN COST FOR STUDENT VISA APPLICATION (SINGLE) &nbsp;•&nbsp; WITHOUT A VOUCHER</div>
<table class="cost">
    <tr class="group"><td colspan="2">School Enrolment and Documentation Fee</td></tr>
    <tr>
        <td>School Enrollment and Documentation Fee</td>
        <td class="right">{{ $fmtFee($school_enrolment_fee) }}</td>
    </tr>
    <tr class="group"><td colspan="2">English Proficiency Exam</td></tr>
    <tr>
        <td>Personalized Review and Unlimited Mock Tests (optional)</td>
        <td class="right">{{ $fmtFee($english_proficiency_fee) }}</td>
    </tr>
    <tr>
        <td>Examination Fee<br><span class="muted">US$ 240.00</span></td>
        <td class="right">{{ $fmtFee($english_proficiency_fee) }}</td>
    </tr>
    <tr class="group"><td colspan="2">Medical Exam</td></tr>
    <tr>
        <td>Medical Examination Fee (Full Medical)<br><span class="muted">Main Applicant &nbsp;·&nbsp; https://nhsgroup.ph/</span></td>
        <td class="right">PhP 14,550.00</td>
    </tr>
    <tr class="group"><td colspan="2">Visa Application Fee</td></tr>
    <tr>
        <td>Professional Fee of Licensed Immigration Adviser<br><span class="muted">Main Applicant &nbsp;·&nbsp; NZ$ 1,500.00</span></td>
        <td class="right">PhP 54,000.00<br><span class="muted">(approximate)</span></td>
    </tr>
    <tr>
        <td>Immigration NZ Fee<br><span class="muted">Main Applicant &nbsp;·&nbsp; NZ$ 850.00</span></td>
        <td class="right">PhP 30,600.00<br><span class="muted">(approximate)</span></td>
    </tr>
    <tr class="group"><td colspan="2">Travel and Medical Expenses</td></tr>
    <tr>
        <td>Travel and Medical Insurance Fee</td>
        <td class="right">PhP 32,500.00<br><span class="muted">(approximate)</span></td>
    </tr>
    <tr class="total"><td>TOTAL EXPENSE (estimate only)</td><td class="right">PHP 260,650.00</td></tr>
    <tr><td>Tuition Fee (depends on the program)</td><td class="right">&hellip;&hellip;</td></tr>
    <tr><td>Travel to New Zealand (flight tickets and other travel expenses)</td><td class="right">&hellip;&hellip;</td></tr>
    <tr class="group"><td colspan="2">SHOW MONEY (required by Immigration New Zealand)</td></tr>
    <tr>
        <td>Living Expenses (per year)<br><span class="muted">NZ$ 20,000.00</span></td>
        <td class="right">PhP 720,000.00<br><span class="muted">(approximate)</span></td>
    </tr>
</table>

<div class="breakdown-caption">BREAKDOWN COST FOR STUDENT VISA APPLICATION (COUPLE) &nbsp;•&nbsp; WITHOUT A VOUCHER</div>

{{-- ── Page 4 ───────────────────────────────────────────────── --}}
<div class="page-break"></div>

<table class="cost">
    <thead>
        <tr><th>Particulars</th><th class="right">Cost</th></tr>
    </thead>
    <tr class="group"><td colspan="2">School Enrolment and Documentation Fee</td></tr>
    <tr>
        <td>School Enrollment and Documentation Fee (couple)</td>
        <td class="right">{{ $fmt(150000) }}</td>
    </tr>
    <tr class="group"><td colspan="2">English Proficiency Exam</td></tr>
    <tr>
        <td>Personalized Review and Unlimited Mock Tests (optional)</td>
        <td class="right">{{ $fmtFee($english_proficiency_fee) }}</td>
    </tr>
    <tr>
        <td>Examination Fee<br><span class="muted">US$ 240.00</span></td>
        <td class="right">{{ $fmtFee($english_proficiency_fee) }}</td>
    </tr>
    <tr class="group"><td colspan="2">Medical Exam</td></tr>
    <tr>
        <td>Medical Examination Fee (Full Medical)<br><span class="muted">Main Applicant &nbsp;·&nbsp; PhP 14,550.00 &nbsp;·&nbsp; Spouse / Partner &nbsp;·&nbsp; PhP 14,550.00</span></td>
        <td class="right">PhP 29,100.00</td>
    </tr>
    <tr class="group"><td colspan="2">Visa Application Fee</td></tr>
    <tr>
        <td>Professional Fee of Licensed Immigration Adviser<br><span class="muted">Main Applicant &nbsp;·&nbsp; NZ$ 1,500.00 &nbsp;·&nbsp; Spouse / Partner &nbsp;·&nbsp; NZ$ 1,500.00</span></td>
        <td class="right">PhP 108,000.00<br><span class="muted">(approximate)</span></td>
    </tr>
    <tr>
        <td>Immigration NZ Fee<br><span class="muted">Main Applicant &nbsp;·&nbsp; NZ$ 850.00 &nbsp;·&nbsp; Spouse / Partner &nbsp;·&nbsp; NZ$ 1,630.00</span></td>
        <td class="right">PhP 90,000.00<br><span class="muted">(approximate)</span></td>
    </tr>
    <tr class="group"><td colspan="2">Travel and Medical Expenses</td></tr>
    <tr>
        <td>Travel and Medical Insurance Fee<br><span class="muted">Main Applicant &nbsp;·&nbsp; NZ$ 900.00 &nbsp;·&nbsp; Spouse / Partner &nbsp;·&nbsp; NZ$ 1,000.00 &nbsp;·&nbsp; Mandatory for students only.</span></td>
        <td class="right">PhP 69,000.00<br><span class="muted">(approximate)</span></td>
    </tr>
    <tr class="total"><td>TOTAL EXPENSE (estimate only)</td><td class="right">PHP 475,100.00</td></tr>
    <tr><td>Tuition Fee (depends on the program)</td><td class="right">&hellip;&hellip;</td></tr>
    <tr><td>Travel to New Zealand (flight tickets and other travel expenses)</td><td class="right">&hellip;&hellip;</td></tr>
    <tr class="group"><td colspan="2">SHOW MONEY (required by Immigration New Zealand)</td></tr>
    <tr>
        <td>Living Expenses (per year for couple)<br><span class="muted">NZ$ 25,000.00</span></td>
        <td class="right">PhP 900,000.00<br><span class="muted">(approximate)</span></td>
    </tr>
    <tr><td>Tuition Fee (depends on the program)</td><td class="right">&hellip;&hellip;</td></tr>
</table>

<div class="article-bar">ARTICLE 2 &nbsp;•&nbsp; SCOPE OF SERVICES</div>

<h3>Section 1. Evaluation and Assessment</h3>
<p>During this phase, the Company shall undertake the collection of necessary documentation, which encompasses your Passport, Curriculum Vitae (CV), Graduate Certificate, and Transcript of Records (TOR), utilizing our designated online submission form. It is imperative to emphasize that the aforementioned documents serve as critical prerequisites for the facilitation of the services outlined within this Agreement. The utilization of our online platform ensures the secure transmission and handling of sensitive information in compliance with prevailing data protection regulations.</p>

<h3>Section 2. Goal Setting Meeting</h3>
<p>The Company will perform a thorough evaluation of your documentation, followed by a goal-setting session to deliberate on diverse options tailored to your individual circumstances. These considerations encompass, but are not limited to, essential criteria such as educational prerequisites, financial viability, sponsorship availability, and temporal constraints. Subsequent to your endorsement of the proposed course of action, progression to Stage 3 shall ensue.</p>

<h3>Section 3. School Application Enrollment</h3>
<p><strong>3.1 Securing your Offer of Place</strong></p>
<p>The Company shall furnish you with a comprehensive checklist delineating the prerequisites for securing an Offer of Place from your selected educational institution. We shall serve as intermediaries in communication with the respective schools, facilitating the compilation and submission of all requisite documentation on your behalf.</p>
<ul>
    <li>We will assist you in filling out and submitting the school application form, along with necessary documents</li>
    <li>Submit all required documents (transcripts, English proficiency, passport, CV, and other)</li>
    <li>Assist you in drafting letter of Intent / Statement of Purpose</li>
    <li>Arrange for schedule of entrance exams and interviews with the institution</li>
    <li>Provide preparation guidance for the exam and interview process</li>
    <li>Receive and confirm the Offer Of Place from the institution to secure your spot.</li>
</ul>
<p>You clearly understand that ePathways Philippines is <strong>not responsible for giving you work in New Zealand</strong>. We are <strong>ONLY</strong> advising and assisting you on the best education pathway that fits your goal.</p>

<h3>Section 4. Visa Processing</h3>
<p><strong>4.1 Endorsement to Licensed Immigration Adviser</strong></p>
<p>Upon the successful conclusion of Section (Stage) 3, the Company shall facilitate your transition to our NZ Professional Licensed Immigration Adviser Team for the processing of your visa application. The Licensed Immigration Adviser will send you a separate engagement agreement to comply with Immigration New Zealand Law.</p>
<p>It is imperative to note that all associated costs pertaining to your visa processing shall be exclusively borne by you and are not included in our consultation fee.</p>
<ul>
    <li>We will schedule a meeting with the LIA if necessary to review the visa requirements and guide you through each step to ensure your application is fully prepared.</li>
    <li>Assist you in compiling your bank statements or financial records as required by the Licensed Immigration Adviser to show sufficient funds to cover tuition and living expenses in New Zealand</li>
    <li>Helping you in drafting your compelling and clear Statement of Purpose</li>
    <li>Verifying all required documents as per the checklist from the LIA</li>
    <li>Ensuring all documents are properly organized, labelled and ready for submission</li>
    <li>Making sure you&rsquo;re aware of any additional documents or forms that may be specific to your visa application type, such as medical or police checks</li>
</ul>

<p><strong>4.2 Visa Lodgement</strong></p>
<p>When your documentation is ready, the Licensed Immigration Adviser will lodge your visa application for both you and your partner (if applicable). This includes the submission, processing and follow up.</p>
<ul>
    <li>Documents will be reviewed, checked and the visa application submitted by the LIA.</li>
    <li>We will monitor the progress of your application, ensuring timely updates and addressing any issues promptly</li>
    <li>The LIA will liaise with New Zealand Immigration to resolve any queries and ensure all required documentation is provided if needed</li>
    <li>We will keep you informed on the status of your visa, which typically takes 4-6 weeks to process, and notify you once a decision is made.</li>
</ul>
<p>We will serve as an intermediary in all communications between yourself and the Licensed Immigration Adviser and assume responsibility for overseeing the entirety of the visa application process.</p>

<h3>Section 5. Visa Outcome</h3>
<p>Upon receipt of the decision outcome of your visa application, the Company shall expeditiously communicate the result to you.</p>

<h3>Section 6. Settlement in New Zealand</h3>
<p>ePathways will facilitate your settlement in New Zealand, orchestrating arrangements such as flight bookings and airport transfers tailored to your specific requirements. This service may incur additional costs.</p>

<div class="article-bar">ARTICLE 3 &nbsp;•&nbsp; DISBURSEMENT / OTHER DIRECT EXPENSES</div>
<p>It is important to note that throughout the course of this process, there may arise sundry additional expenditures, commonly referred to as disbursements, which encompass, albeit are not confined to, medical expenses, visa application fees, and incidental travel-related costs.</p>
<p>It is imperative to underscore that these ancillary costs are not encompassed within the ambit of our prescribed fees, thus necessitating your direct assumption of responsibility for their settlement. Therefore, it is incumbent upon you, the Client, to bear full accountability for all such associated disbursements.</p>

<div class="article-bar">ARTICLE 4 &nbsp;•&nbsp; TERMINATION</div>
<p>Either party reserves the right to terminate this Agreement by issuing a written notification. Termination shall be deemed effective upon receipt of the written notice.</p>

<div class="article-bar">ARTICLE 5 &nbsp;•&nbsp; GOVERNING LAW</div>
<p>This Agreement shall be subject to and interpreted in accordance with the laws prevailing in your country.</p>

<div class="article-bar">ARTICLE 6 &nbsp;•&nbsp; DATA PRIVACY</div>
<p><strong>Section 1.</strong> The Company shall comply with the requirements under the Data Privacy Act of 2012, and such rules, orders, and regulations as may be issued by the National Privacy Commission (&ldquo;NPC&rdquo;) in relation to the processing and possession of Personal Information and/or Sensitive Personal Information (as such terms are defined in the Data Privacy Act of 2012) comprising the Data.</p>
<p>&ldquo;Data&rdquo; means the contract files, materials and other information in physical, electronic or any other form pertaining to the Services, as communicated and provided by the Company. Data shall include All Personal Information and Sensitive Personal Information as defined in the Data Privacy Act of 2012, pertaining to the personnel or to any third party whose Personal Information and/or Sensitive Personal Information as disclosed by the Company in accordance with the Agreement.</p>
<p><strong>Section 2.</strong> The Company shall ensure that only authorized personnel shall process or have access to the Data. Confidentiality obligations shall be imposed on such personnel evidenced by a written confidentiality agreement which shall survive the term of employment of the employee.</p>
<p><strong>Section 3.</strong> The Company shall be responsible for the custody and safekeeping of the Data, immediately from the effectivity of this Agreement.</p>

<p>Should you concur with the terms delineated herein and elect to avail yourself of our services, kindly affix your signature and promptly return a duplicate of this Agreement to signify your acceptance thereof. Should any queries arise or if further elucidation is warranted, we encourage you to promptly reach out to us for clarification. Anticipating the privilege of accompanying you on this journey and facilitating the realization of your educational and settlement objectives in New Zealand, we remain at your service.</p>

{{-- ── Page 9 ───────────────────────────────────────────────── --}}
<div class="page-break"></div>

<div class="article-bar" style="margin-top:0;">EXECUTION &amp; ACCEPTANCE</div>

<p><strong>IN WITNESS WHEREOF</strong>, both parties have hereunto set their hands this <span class="strong" style="text-decoration: underline">{{ $generated_at_formatted }}</span>.</p>

<table class="signature-table">
    <thead>
        <tr><th>CLIENT</th><th>COMPANY</th></tr>
    </thead>
    <tbody>
        <tr>
            <td style="text-align:center;">
                {{-- Two elements: an <img> the sign modal streams into
                     via postMessage in preview, and a fallback caption
                     when no signature yet. Both toggled by the script
                     block near the end of the doc. --}}
                <img id="ep-client-signature"
                    src="{{ $client_signature ?? '' }}"
                    alt="Client signature"
                    style="max-height:48px; max-width:160px; margin:0 auto 2px auto; display:{{ ! empty($client_signature ?? null) ? 'block' : 'none' }};">
                <div id="ep-client-sig-fallback" style="font-style:italic; font-size:8pt; color:#888; text-align:left; display:{{ empty($client_signature ?? null) ? 'block' : 'none' }};">
                    Insert your e-signature above, or place a check mark (&#10004;) below if unavailable.
                </div>
                <div id="ep-client-sig-name" class="sig-name" style="margin-top:{{ ! empty($client_signature ?? null) ? '0' : '22px' }};">{{ $client_name ?: 'INSERT NAME HERE' }}</div>
                <div class="sig-role">Client</div>
            </td>
            <td style="text-align:center;">
                @if (! empty($signer_signature))
                    <img src="{{ $signer_signature }}" alt="Signature" style="max-height:48px; max-width:160px; margin:0 auto 2px auto; display:block;">
                @else
                    <div style="font-style:italic; font-size:8pt; color:#888; text-align:left;">Authorized signatory on behalf of the Company.</div>
                @endif
                <div class="sig-name" style="margin-top:{{ ! empty($signer_signature) ? '0' : '22px' }};">{{ $signer_name ?? 'Neil Bryan Escaner' }}</div>
                <div class="sig-role">ePathways &mdash; Philippines</div>
            </td>
        </tr>
        <tr class="sig-meta-row">
            <td><strong>Date:</strong> ____________________________</td>
            <td><strong>Date:</strong> ____________________________</td>
        </tr>
        <tr class="sig-meta-row">
            <td><strong>Mobile:</strong> ____________________________</td>
            <td><strong>Mobile:</strong> {{ $signer_mobile ?? '+63945 107 6871' }} <em>[WhatsApp]</em></td>
        </tr>
    </tbody>
</table>

<div class="ack-box">
    <span id="ep-ack-mark">{!! ($acknowledged ?? false) ? '&#9745;' : '&#9744;' !!}</span>&nbsp;&nbsp; I have read and agreed to the Consultancy Agreement terms.
</div>

@if (($preview ?? false))
    {{-- Preview-only: listen for the client sign modal streaming a
         signature dataURL + ack toggle into the preview iframe. Wrapped
         so dompdf's HTML pipeline never sees it. --}}
    <script>
        (function () {
            var sigImg = document.getElementById('ep-client-signature');
            var sigFallback = document.getElementById('ep-client-sig-fallback');
            var sigName = document.getElementById('ep-client-sig-name');
            var ackMark = document.getElementById('ep-ack-mark');
            window.addEventListener('message', function (e) {
                var d = e.data || {};
                if (d.type === 'applicant-signature') {
                    if (d.value) {
                        if (sigImg) { sigImg.src = d.value; sigImg.style.display = 'block'; }
                        if (sigFallback) sigFallback.style.display = 'none';
                        if (sigName) sigName.style.marginTop = '0';
                    } else {
                        if (sigImg) { sigImg.src = ''; sigImg.style.display = 'none'; }
                        if (sigFallback) sigFallback.style.display = 'block';
                        if (sigName) sigName.style.marginTop = '22px';
                    }
                } else if (d.type === 'acknowledged') {
                    if (ackMark) ackMark.innerHTML = d.value ? '&#9745;' : '&#9744;';
                }
            });
        })();
    </script>
@endif

{{-- Footer text. dompdf substitutes {PAGE_NUM}/{PAGE_COUNT} here, which is
     the only way to get a total page count — counter(pages) always yields 0.
     Requires isPhpEnabled, which AgreementGenerator turns on for this render
     only. Browsers ignore an unknown script type, so the live HTML preview
     is unaffected. --}}
<script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->getFont('DejaVu Sans', 'bold');
        $size = 7.5;
        $text = 'ePathways Philippines Consultancy  |  Consultancy Agreement  |  Page {PAGE_NUM} of {PAGE_COUNT}';
        $x = ($pdf->get_width() - $fontMetrics->getTextWidth($text, $font, $size)) / 2;
        $pdf->page_text($x, $pdf->get_height() - 46, $text, $font, $size, [0.26, 0.38, 0.21]);
    }
</script>

</body>
</html>
