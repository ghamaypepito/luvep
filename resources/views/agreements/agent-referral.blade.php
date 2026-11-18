{{--
    Affiliate Partner Agreement — Student Referral & Enrolment Services (NZ & AU).

    Payload: $fields (array of editable values), $agent_name, $signer_name,
    $signer_signature (eP side), $agent_signature (affiliate side), $preview.
    Empty editable fields render a grey placeholder guide via the $fld() helper.
--}}
@php
    $fields = $fields ?? [];
    // Render an editable value, or a grey placeholder guide when it's blank.
    $fld = function ($key, $guide) use ($fields) {
        $v = trim((string) ($fields[$key] ?? ''));
        $value = $v !== '' ? e($v) : '<span class="ph">'.e($guide).'</span>';

        return '<!--affiliate-field:'.$key.'-->'.$value.'<!--/affiliate-field:'.$key.'-->';
    };

    $preview = $preview ?? false;
    $logoSrc = $preview ? asset('images/ep-logo.png') : public_path('images/ep-logo.png');
    $font = fn ($file) => $preview ? asset("fonts/urbanist/{$file}") : public_path("fonts/urbanist/{$file}");
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Affiliate Partner Agreement — {{ $agent_name ?? '' }}</title>
<style>
    @font-face { font-family: 'Urbanist'; font-style: normal; font-weight: 400; src: url('{{ $font("Urbanist-Regular.ttf") }}') format('truetype'); }
    @font-face { font-family: 'Urbanist'; font-style: italic; font-weight: 400; src: url('{{ $font("Urbanist-Italic.ttf") }}') format('truetype'); }
    @font-face { font-family: 'Urbanist'; font-style: normal; font-weight: 700; src: url('{{ $font("Urbanist-Bold.ttf") }}') format('truetype'); }

    @page { margin: 144px 62px 75px 62px; }
    body.preview { padding: 24px 62px; }
    body { font-family: 'Urbanist', DejaVu Sans, sans-serif; font-size: 10pt; color: #111; line-height: 1.45; }

    @media screen {
        body { max-width: 794px; margin: 0 auto; padding: 24px 55px; background: #fff; }
        .page-header { position: static; text-align: center; margin: -8px 0 12px 0; }
        .page-header img { height: 88px; }
    }

    .page-header { position: fixed; top: -116px; left: 0; right: 0; text-align: center; border-bottom: 1.5px solid #436235; padding-bottom: 10px; }
    .page-header img { height: 100px; width: auto; }
    .page-header.in-flow { position: static; margin-bottom: 16px; }
    .page-footer { position: fixed; bottom: -34px; left: 0; right: 0; border-top: 1.5px solid #436235; }

    .page-break { page-break-before: always; }
    .no-break { page-break-inside: avoid; }
    .eyebrow { text-align: center; color: #436235; font-weight: bold; font-size: 9pt; letter-spacing: 2px; margin-bottom: 6px; }
    h1 { text-align: center; font-size: 21pt; font-weight: 900; letter-spacing: 1px; margin: 0 0 4px 0; color: #14213b; }
    .subtitle { text-align: center; color: #555; font-size: 10pt; font-style: italic; margin-bottom: 16px; }
    hr { border: 0; border-top: 1.5px solid #436235; margin: 14px 0; }
    h2 { color: #14213b; font-size: 12pt; margin: 16px 0 6px 0; }
    h2.sched { color: #436235; }
    h3 { color: #436235; font-size: 10.5pt; margin: 12px 0 5px 0; }
    p { margin: 7px 0; text-align: justify; }
    ol, ul { margin: 6px 0 10px 20px; padding: 0; }
    li { margin: 4px 0; }
    .strong { font-weight: bold; }
    .name-strong { font-weight: bold; color: #556B2F; }
    .company-name { font-weight: bold; font-style: italic; }
    .ph { color: #b0b0b0; font-style: italic; }

    table.sched { width: 100%; border-collapse: collapse; margin: 8px 0 14px 0; }
    table.sched th { background: #14213b; color: #fff; text-align: left; padding: 6px 10px; font-size: 9.5pt; }
    table.sched td { border: 1px solid #d8d8d8; padding: 6px 10px; font-size: 9.5pt; vertical-align: top; }
    table.sched td.key { width: 38%; font-weight: bold; color: #14213b; background: #f6f7f9; }
    table.sched td.red { color: #c0392b; font-weight: bold; }

    .comment-box { border: 1px solid #d8d8d8; padding: 10px 12px; margin: 4px 0 14px 0; font-size: 9.5pt; min-height: 46px; }

    .signature-table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    .signature-table th { background: #436235; color: #fff; text-align: center; padding: 6px; font-size: 10pt; }
    .signature-table td { border: 1px solid #436235; padding: 12px; vertical-align: top; font-size: 10pt; width: 50%; }
    .sig-name { font-weight: bold; text-align: center; margin-top: 20px; }
    .sig-label { text-align: center; font-size: 8pt; color: #888; letter-spacing: 1px; }
    .exec-cell { text-align: center; }
    .exec-line { margin-top: 12px; }
    .exec-val { text-align: center; font-weight: bold; }
</style>
</head>
<body class="{{ $preview ? 'preview' : '' }}">

<div class="page-header{{ $preview ? ' in-flow' : '' }}">
    <img src="{{ $logoSrc }}" alt="eP">
</div>
@unless ($preview)<div class="page-footer"></div>@endunless

<div class="eyebrow">OFFICIAL DOCUMENT &nbsp;•&nbsp; CONFIDENTIAL</div>
<h1>AFFILIATE PARTNER AGREEMENT</h1>
<div class="subtitle">Student Referral and Enrolment Services — New Zealand &amp; Australia</div>
<hr>

<p>This Referral Affiliate Partner Agreement (the &ldquo;Agreement&rdquo;) is made and entered into on this {!! $fld('effective_date', '[effective date — e.g. 30th day of June, 2026]') !!} (the &ldquo;Effective Date&rdquo;), by and between:</p>

<p><span class="company-name">Employment Pathways Limited</span>, a company duly organised and existing under the laws of New Zealand, with New Zealand Business Number (NZBN) 9429050526901 and registered office at 15 Mercari Way, Albany, Auckland 0632, New Zealand, trading as &ldquo;eP&rdquo; (hereinafter referred to as &ldquo;eP&rdquo; or the &ldquo;Company&rdquo;);</p>
<p style="text-align:center;">and</p>
<p><span class="name-strong">{!! $fld('agent_full_name', '[Affiliate Partner full name]') !!}</span>, a citizen of {!! $fld('agent_citizenship', '[citizenship]') !!} holding Passport Number {!! $fld('agent_passport', '[passport number]') !!}, of {!! $fld('agent_city', '[city, country]') !!} (hereinafter referred to as the &ldquo;Affiliate Partner&rdquo;).</p>
<p>eP and the Affiliate Partner are each referred to as a &ldquo;Party&rdquo; and collectively as the &ldquo;Parties&rdquo;.</p>

<h2>1. Background</h2>
<p>eP is an education consultancy firm that assists prospective students with enrolment into educational institutions and with study and migration pathways in New Zealand and Australia.</p>
<p>The Affiliate Partner is engaged in identifying and referring prospective students who wish to study in New Zealand and Australia.</p>
<p>The Parties wish to set out the terms on which the Affiliate Partner will refer prospective students to eP, and on which eP will pay commission to the Affiliate Partner, on the terms and conditions set out in this Agreement.</p>

<h2>2. Appointment of the Affiliate Partner</h2>
<p>eP hereby appoints the Affiliate Partner as a non-exclusive referral Affiliate Partner for the purpose of introducing and referring prospective students to eP, and the Affiliate Partner accepts such appointment, on the terms set out in this Agreement.</p>
<p>The Affiliate Partner is authorised only to introduce and refer prospective students to eP. The Affiliate Partner has no authority to negotiate, accept, or sign any contract, or to make any representation, warranty, or commitment, on behalf of eP or any educational institution, unless expressly authorised in writing by eP.</p>

<h2>3. Exclusivity</h2>
<p>In respect of New Zealand and Australia, the Affiliate Partner agrees to refer all of the Affiliate Partner&rsquo;s clients and prospective students exclusively through eP.</p>
<p>The Affiliate Partner shall not approach, contact, apply to, or deal directly with any educational institution, school, college, or university in New Zealand or Australia on behalf of any client or prospective student. All such dealings shall be conducted by and through eP.</p>
<p>eP shall be solely responsible for processing and lodging the enrolment of each referred student with the relevant educational institution, and for providing guidance to the referred student regarding their study and migration pathways in New Zealand and Australia.</p>
<p>Any attempt by the Affiliate Partner to enrol a client directly with an institution in New Zealand or Australia, or to bypass eP, shall constitute a material breach of this Agreement and may result in forfeiture of any commission otherwise payable in respect of that client and immediate termination of this Agreement.</p>

<h2>4. Obligations of the Affiliate Partner</h2>
<p>The Affiliate Partner shall:</p>
<ul>
    <li>Identify, introduce, and refer prospective students to eP in a professional and ethical manner;</li>
    <li>Provide accurate and complete information about each referred student to eP and promptly pass on any documents reasonably required to process the enrolment;</li>
    <li>Present eP and the services offered honestly and not make any false, misleading, or exaggerated representation to any prospective student;</li>
    <li>Not make any guarantee or promise regarding admission, visa outcome, work rights, or residency to any prospective student;</li>
    <li>Comply with all applicable laws in the jurisdictions in which the Affiliate Partner operates; and</li>
    <li>Refer all clients for New Zealand and Australia exclusively through eP in accordance with Clause 3.</li>
</ul>

<h2>5. Obligations of eP</h2>
<p>eP shall:</p>
<ul>
    <li>Process and lodge the enrolment of each referred student with the relevant educational institution in New Zealand or Australia;</li>
    <li>Provide guidance and counselling to each referred student regarding their study and migration pathways in New Zealand and Australia;</li>
    <li>Keep the Affiliate Partner reasonably informed of the progress of each referred student&rsquo;s application and enrolment;</li>
    <li>Pay the Affiliate Partner the commission due in accordance with Clause 6 and Schedule A; and</li>
    <li>Deal with all referred students and their information in accordance with applicable privacy and data-protection laws.</li>
</ul>

<h2>6. Commission and Payment</h2>
<p>eP shall pay the Affiliate Partner commission for each student referred by the Affiliate Partner who enrols through eP and commences their course, calculated in accordance with the rates set out in Schedule A.</p>
<p>For students enrolling in New Zealand, the commission rate is determined by the total number of students referred by the Affiliate Partner and enrolled through eP within the same calendar year, as set out in Schedule A.</p>
<p>For students enrolling in Australia, the commission shall be negotiated and agreed between the Parties in writing on a case-by-case basis before the relevant referral is processed.</p>
<p>Commission becomes due and payable only once the referred student has commenced their course in the country of their choice. No commission is payable in respect of any student who does not enrol, or who enrols but does not commence their course, for any reason.</p>
<p>eP shall pay the commission due to the Affiliate Partner within fifteen (15) days after confirmation that the referred student has commenced their course, by bank transfer to an account nominated by the Affiliate Partner in writing. Any bank charges, currency-conversion costs, or applicable taxes shall be borne as agreed by the Parties in writing.</p>
<p>All commission amounts are stated in Philippine Pesos (PhP) unless otherwise agreed in writing.</p>

<h2>7. Independent Contractor</h2>
<p>The Affiliate Partner is an independent contractor and not an employee, partner, joint venturer, or Affiliate Partner of eP for any purpose other than the limited referral purpose set out in this Agreement.</p>
<p>Nothing in this Agreement creates an employment relationship. The Affiliate Partner is responsible for the Affiliate Partner&rsquo;s own taxes, levies, insurance, and operating expenses, and is not entitled to any employee benefits from eP.</p>

<h2>8. Confidentiality and Data Protection</h2>
<p>Each Party shall keep confidential all non-public information of the other Party and of any referred student, including personal information, commercial terms, and the contents of this Agreement, and shall use such information only for the purposes of performing this Agreement.</p>
<p>Each Party shall handle the personal information of referred students in accordance with all applicable privacy and data-protection laws, including the New Zealand Privacy Act 2020 and any other privacy laws applicable to the Parties or the students.</p>
<p>The obligations in this Clause survive the termination or expiry of this Agreement.</p>

<h2>9. Term and Termination</h2>
<p>This Agreement commences on the Effective Date and continues for an initial term of five (5) years, after which it shall automatically renew for successive one (1) year terms unless terminated in accordance with this Clause.</p>
<p>Either Party may terminate this Agreement for convenience by giving the other Party not less than thirty (30) days&rsquo; prior written notice.</p>
<p>Either Party may terminate this Agreement immediately by written notice if the other Party commits a material breach of this Agreement that is not remedied within fourteen (14) days of written notice, or that is incapable of remedy.</p>
<p>Termination does not affect the Affiliate Partner&rsquo;s entitlement to commission in respect of any student who was referred and who commences their course before or after termination, where commission has accrued in accordance with Clause 6, provided the referral was made in compliance with this Agreement.</p>

<h2>10. Liability and Indemnity</h2>
<p>Neither Party shall be liable for any indirect, incidental, or consequential loss arising out of or in connection with this Agreement.</p>
<p>The Affiliate Partner shall indemnify eP against any loss, claim, or liability arising from any false or misleading representation made by the Affiliate Partner, or from any breach of this Agreement or of applicable law by the Affiliate Partner.</p>
<p>Neither Party shall be liable for any failure or delay in performing its obligations under this Agreement that is caused by an event beyond its reasonable control, including acts of God, natural disaster, epidemic or pandemic, government action, or changes in immigration or education policy (&ldquo;Force Majeure&rdquo;).</p>

<h2>11. Governing Law and Dispute Resolution</h2>
<p>This Agreement is governed by and construed in accordance with the laws of where the Affiliate Partner is located and its jurisdiction.</p>
<p>The Parties shall first attempt to resolve any dispute arising out of or in connection with this Agreement amicably through good-faith negotiation. If the dispute is not resolved within thirty (30) days, it shall be submitted to the exclusive jurisdiction of the courts of New Zealand.</p>

<h2>12. General Provisions</h2>
<p>This Agreement constitutes the entire agreement between the Parties and supersedes all prior discussions, representations, and agreements relating to its subject matter.</p>
<p>No variation of this Agreement is effective unless made in writing and signed by both Parties.</p>
<p>Neither Party may assign or transfer its rights or obligations under this Agreement without the prior written consent of the other Party.</p>
<p>If any provision of this Agreement is held to be invalid or unenforceable, the remaining provisions shall continue in full force and effect.</p>
<p>This Agreement may be signed in counterparts, including by electronic signature and electronic copy, each of which is deemed an original and all of which together constitute one agreement.</p>

<div class="page-break"></div>

<h2 class="sched">Schedule A — Commission Structure</h2>
<p>eP shall pay the Affiliate Partner a commission for each student referred by the Affiliate Partner who enrols through eP and commences their course in the destination country, in accordance with the rates and terms below.</p>

<h3>Commission rates</h3>
<table class="sched no-break">
    <thead>
        <tr>
            <th>Students referred &amp; enrolled per calendar year</th>
            <th style="width:22%;">Percentage</th>
            <th style="width:34%;">Commission per enrolled student</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td class="key">New Zealand — 1 to 5 students</td>
            <td>{!! $fld('nz_1_5_percent', 'Percentage') !!}</td>
            <td class="red">{!! $fld('nz_1_5_amount', 'Insert Amount') !!}</td>
        </tr>
        <tr>
            <td class="key">New Zealand — 6 or more students</td>
            <td>{!! $fld('nz_6plus_percent', 'Percentage') !!}</td>
            <td class="red">{!! $fld('nz_6plus_amount', 'Insert Amount') !!}</td>
        </tr>
        <tr>
            <td class="key">Australia — all students</td>
            <td>{!! $fld('au_percent', 'Percentage') !!}</td>
            <td class="red">{!! $fld('au_amount', 'Insert Amount') !!}</td>
        </tr>
    </tbody>
</table>

<p><strong>How the rate is applied:</strong> the New Zealand rate is set by the total number of students referred and enrolled in the same calendar year. Once the Affiliate Partner refers six (6) or more students in a year, the higher New Zealand rate in Schedule A applies to every qualifying student referred in that year.</p>

<h3>Payment &amp; other terms</h3>
<table class="sched no-break">
    <tbody>
        <tr><td class="key">Commission basis</td><td>{!! $fld('commission_basis', 'Per-student fee') !!}</td></tr>
        <tr><td class="key">Payment trigger</td><td>{!! $fld('payment_trigger', 'Enrolment and commencement of the course') !!}</td></tr>
        <tr><td class="key">Payment timing</td><td>{!! $fld('payment_timing', 'Within 15 days after the student commences the course') !!}</td></tr>
        <tr><td class="key">Currency</td><td class="red">{!! $fld('currency', 'Philippine Peso (PhP)') !!}</td></tr>
        <tr><td class="key">Australia students</td><td>{!! $fld('australia_students', 'Commission is negotiable and agreed in writing before the referral is processed.') !!}</td></tr>
        <tr><td class="key">Refunds &amp; withdrawals</td><td>{!! $fld('refunds_withdrawals', 'No commission is payable where a student does not commence, withdraws, or whose fees are refunded.') !!}</td></tr>
    </tbody>
</table>

<h3>Add Comment</h3>
<div class="comment-box">{!! $fld('schedule_a_comment', 'Use this space to record any comments, clarifications, or supplementary terms agreed between eP and the Affiliate Partner.') !!}</div>

<div class="page-break"></div>

<h2 class="sched">Schedule B — Bank Account of Affiliate Partner</h2>
<p>The Affiliate Partner provides the following account details for payment of commission under this Agreement. All payments will be made by electronic transfer to the account nominated below, in accordance with the payment terms in Schedule A.</p>

<table class="sched no-break">
    <tbody>
        <tr><td class="key">Bank Name</td><td>{!! $fld('bank_name', 'Insert bank') !!}</td></tr>
        <tr><td class="key">Account Holder Name <span style="font-weight:normal;">(as registered with the bank)</span></td><td>{!! $fld('account_holder', 'Insert Account Holder Name') !!}</td></tr>
        <tr><td class="key">Account number</td><td>{!! $fld('account_number', 'Insert Account Number') !!}</td></tr>
        <tr><td class="key">SWIFT / BIC code</td><td>{!! $fld('swift_bic', 'Insert SWIFT / BIC code') !!}</td></tr>
    </tbody>
</table>

<div class="page-break"></div>

<div class="eyebrow" style="text-align:left; letter-spacing:2px;">EXECUTION</div>
<h2 style="margin-top:2px;">Signatures</h2>
<p><strong>IN WITNESS WHEREOF</strong>, the Parties have executed this Agreement as of the Effective Date first written above.</p>

<table class="signature-table">
    <thead>
        <tr><th>For eP,</th><th>For Affiliate Partner,</th></tr>
    </thead>
    <tbody>
        <tr>
            <td class="exec-cell" style="height:64px;">
                <!--affiliate-signature:company-->
                @if (! empty($signer_signature))
                    <img src="{{ $signer_signature }}" alt="Signature" style="max-height:46px; max-width:150px; margin:0 auto 2px auto; display:block;">
                @endif
                <!--/affiliate-signature:company-->
                <div class="sig-name" style="margin-top:{{ ! empty($signer_signature) ? '0' : '20px' }};">{!! $fld('company_signatory', 'Dinah Suarin') !!}</div>
                <div class="sig-label">FULL NAME</div>
            </td>
            <td class="exec-cell" style="height:64px;">
                <!--affiliate-signature:agent-->
                @if (! empty($agent_signature))
                    <img src="{{ $agent_signature }}" alt="Affiliate signature" style="max-height:46px; max-width:150px; margin:0 auto 2px auto; display:block;">
                @endif
                <!--/affiliate-signature:agent-->
                <div class="sig-name" style="margin-top:{{ ! empty($agent_signature) ? '0' : '20px' }};">{!! $fld('agent_full_name', 'Affiliate Partner Name') !!}</div>
                <div class="sig-label">FULL NAME</div>
            </td>
        </tr>
        <tr>
            <td class="exec-cell"><div class="exec-val">{!! $fld('company_title', 'Founder') !!}</div><div class="sig-label">TITLE</div></td>
            <td class="exec-cell"><div class="exec-val">{!! $fld('agent_title', 'Affiliate Partner') !!}</div><div class="sig-label">TITLE</div></td>
        </tr>
        <tr>
            <td class="exec-cell"><div class="exec-val"><!--affiliate-date:company-->{!! ! empty($company_signed_date) ? e($company_signed_date) : $fld('company_date', '____________') !!}<!--/affiliate-date:company--></div><div class="sig-label">DATE</div></td>
            <td class="exec-cell"><div class="exec-val"><!--affiliate-date:agent-->{!! ! empty($agent_signed_date) ? e($agent_signed_date) : $fld('agent_date', '____________') !!}<!--/affiliate-date:agent--></div><div class="sig-label">DATE</div></td>
        </tr>
        <tr>
            <td class="exec-cell"><div class="exec-val">15 Mercari Way, Albany, Auckland 0632, New Zealand</div><div class="sig-label">BUSINESS ADDRESS</div></td>
            <td class="exec-cell"><div class="exec-val">{!! $fld('affiliate_business_address', '____________') !!}</div><div class="sig-label">BUSINESS ADDRESS</div></td>
        </tr>
        <tr>
            <td class="exec-cell"><div class="exec-val">dinah@luvep.com</div><div class="sig-label">EMAIL</div></td>
            <td class="exec-cell"><div class="exec-val">{!! $fld('affiliate_email', '____________') !!}</div><div class="sig-label">EMAIL</div></td>
        </tr>
        <tr>
            <td class="exec-cell"><div class="exec-val">+64 27 777 5586</div><div class="sig-label">CONTACT NUMBER</div></td>
            <td class="exec-cell"><div class="exec-val">{!! $fld('affiliate_contact', '____________') !!}</div><div class="sig-label">CONTACT NUMBER</div></td>
        </tr>
    </tbody>
</table>

<script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->getFont('DejaVu Sans', 'bold');
        $size = 7.5;
        $text = 'eP  |  Affiliate Partner Agreement  |  Page {PAGE_NUM} of {PAGE_COUNT}';
        $x = ($pdf->get_width() - $fontMetrics->getTextWidth($text, $font, $size)) / 2;
        $pdf->page_text($x, $pdf->get_height() - 46, $text, $font, $size, [0.26, 0.38, 0.21]);
    }
</script>

</body>
</html>
