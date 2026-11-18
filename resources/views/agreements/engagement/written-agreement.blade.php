@extends('agreements.engagement.layout')

@section('body')
    <div class="doc-title">Written Agreement for Immigration Advice Services</div>
    <div class="doc-sub">D Immigration Consultancy Limited, trading as ePathways Migration</div>
    <hr class="title-rule">

    <h2 class="section">1. PARTIES</h2>
    <div class="clause"><span class="n">1.1</span> This Agreement is made between:</div>
    <table class="data">
        <tr><td class="label">Name</td><td>D Immigration Consultancy Limited T/A ePathways Migration</td></tr>
        <tr><td class="label">Address</td><td>15 Mercari Way, Albany, Auckland 0632, New Zealand</td></tr>
        <tr><td class="label">Phone</td><td>{{ $contact['phone'] }}</td></tr>
        <tr><td class="label">Email</td><td>{{ $contact['email'] }}</td></tr>
    </table>
    <p class="muted">hereinafter referred to as 'We', 'Us', 'Our'</p>
    <p>And</p>
    <table class="data">
        <tr><td class="label">Client Name</td><td class="{{ $client['name'] ? '' : 'fill' }}">{{ $client['name'] ?: '[Client Full Name]' }}</td></tr>
        <tr><td class="label">Client Address</td><td class="{{ $client['address'] ? '' : 'fill' }}">{{ $client['address'] ?: '[Client Address]' }}</td></tr>
        <tr><td class="label">Client Phone</td><td class="{{ $client['phone'] ? '' : 'fill' }}">{{ $client['phone'] ?: '[Client Phone]' }}</td></tr>
        <tr><td class="label">Client Email</td><td class="{{ $client['email'] ? '' : 'fill' }}">{{ $client['email'] ?: '[Client Email]' }}</td></tr>
    </table>
    <p class="muted">hereinafter referred to as 'the Client', 'You', 'Your'</p>

    <h2 class="section">2. RESPONSIBLE ADVISERS AND AUTHORITY TO ACT</h2>
    <div class="clause"><span class="n">2.1</span> The following licensed immigration advisers are responsible for providing You with the immigration advice services set out in this Agreement:</div>
    <table class="data head">
        <tr><th>Role</th><th>Name</th><th>Type of Licence</th><th>Licence Number</th></tr>
        @if(!empty($advisers ?? []))
            @foreach($advisers as $a)
                <tr><td>{{ $a['role'] }}</td><td>{{ $a['name'] }}</td><td>{{ $a['licence_type'] }}</td><td>{{ $a['licence_number'] }}</td></tr>
            @endforeach
        @else
            <tr><td>Main adviser</td><td>[Full Name of Immigration Adviser]</td><td>&mdash;</td><td>&mdash;</td></tr>
        @endif
    </table>
    <div class="clause"><span class="n">2.2</span> You authorise all licensed immigration advisers named in clause 2.1 to act for You in relation to the Immigration Matter identified in this Agreement.</div>

    <h2 class="section">3. SUPERVISION</h2>
    @if(!empty($supervision ?? null))
        <div class="clause"><span class="n">3.1</span> {{ $supervision['supervisor']['name'] }}, Licence Number {{ $supervision['supervisor']['licence_number'] }}, is the Supervisor.</div>
        <div class="clause"><span class="n">3.2</span> {{ $supervision['provisional']['name'] }} is the holder of a provisional immigration adviser licence, which allows them to provide immigration advice in relation to all immigration matters while working under the direct supervision of the Supervisor, who holds a full immigration adviser licence.</div>
        <div class="clause"><span class="n">3.3</span> {{ $supervision['provisional']['name'] }} must seek advice from the Supervisor whenever necessary.</div>
        <div class="clause"><span class="n">3.4</span> Any personal information You provide will be shared with the Supervisor, as part of the supervision arrangement between {{ $supervision['provisional']['name'] }} and the Supervisor.</div>
        <div class="clause"><span class="n">3.5</span> The Supervisor is required to keep Your personal information confidential under clause 12(d) of the Licensed Immigration Advisers Code of Conduct 2014.</div>
    @else
        <div class="clause"><span class="n">3.1</span> All licensed immigration advisers named in clause 2.1 hold full immigration adviser licences. No supervision arrangement applies to this Agreement.</div>
    @endif

    <h2 class="section">4. OUR SERVICE</h2>
    <div class="clause"><span class="n">4.1</span> We will provide You the service in relation to Your application to Immigration New Zealand, as described below:</div>
    <ul>
        @if(!empty($applicants ?? []))
            @php
                $applicantParts = [];
                foreach ($applicants as $ap) {
                    $applicantParts[] = '<strong>'.e($ap['visa']).'</strong> &ndash; '.e($ap['name']);
                }
            @endphp
            <li>Client's application to Immigration New Zealand for<br>[{!! implode(';<br>', $applicantParts) !!}].</li>
        @else
            <li>Client's application to Immigration New Zealand for <span class="{{ $visa_category ? '' : 'fill' }}">{{ $visa_category ?: '[Visa Category / Application Type]' }}</span>.</li>
        @endif
    </ul>
    <div class="clause"><span class="n">4.2</span> We anticipate that Your Immigration Matter will involve the following services, split into the stages of work below:</div>
    <table class="data head">
        <tr><th style="width:14%">Stage</th><th>Services to be undertaken</th></tr>
        <tr><td>1</td><td>
            • Providing a personalised and tailored checklist<br>
            • Reviewing all information and documents provided by You<br>
            • Providing advice as to New Zealand immigration law and instructions in relation to this matter, including advice as to Your options and the best way forward
        </td></tr>
        <tr><td>2</td><td><strong>Visa Lodgement</strong><br>
            • We will confirm to You when Your visa application has been submitted to Immigration New Zealand<br>
            • Providing ongoing, timely updates
        </td></tr>
        <tr><td>3</td><td><strong>Visa Outcome</strong><br>• Explaining the visa outcome to You</td></tr>
    </table>
    <div class="clause"><span class="n">4.3</span> Our Services do not include the following, which are considered Extra Services:</div>
    <ul>
        <li>Drafting and responding to any Request for Information (RFI) or Potentially Prejudicial Information (PPI) letters, including but not limited to matters involving non-declaration of material information that should have been transparently disclosed, such as character issues, health issues, or adverse immigration history in any country.</li>
        <li>Submitting any reconsideration request following the decline of a visa application.</li>
        <li>Where an RFI, PPI, and/or a reconsideration application is required, We will first discuss the additional costs involved in preparing such responses and will seek Your prior approval before proceeding.</li>
    </ul>
    <div class="callout"><span class="n">4.4</span> We are unable to guarantee the outcome of Your Immigration Matter; however, We will undertake to use Our professional skill for Your benefit to the best of Our ability at all times.</div>

    <h2 class="section">5. OUR FEE(S)</h2>
    <div class="clause"><span class="n">5.1</span> Our fees are quoted in New Zealand Dollars (NZD).</div>
    <div class="clause"><span class="n">5.2</span> You will pay the following fee for Our service described above:</div>
    <table class="data head">
        <tr><th>Description</th><th class="amount">Costs (NZ$)</th></tr>
        <tr><td>Consulting and service fee for Our service above</td><td class="amount {{ $professional_fee ? '' : 'fill' }}">{{ $professional_fee ? ($professional_fee.(($gst_inclusive ?? false) ? '+GST' : '')) : '[Amount]' }}</td></tr>
        @foreach(($professional_lines ?? []) as $pl)
            <tr><td style="padding-left:22px; color:#4b5563;">{{ $pl['label'] }}</td><td class="amount">{{ $pl['amount'] ?: '—' }}</td></tr>
        @endforeach
        <tr><td><strong>TOTAL</strong></td><td class="amount {{ ($professional_fee_total ?? null) ? '' : 'fill' }}"><strong>{{ ($professional_fee_total ?? null) ?: '[Total Fee]' }}</strong></td></tr>
    </table>
    <div class="clause"><span class="n">5.3</span> Our fee noted above is a set fee that covers all work of a standard nature involved in delivering the service described in this Agreement.</div>
    <div class="clause"><span class="n">5.4</span> If any extra work is required involving additional fees, We will advise You of the additional fees, the reasons why, and obtain Your written approval. Any extra charges would be at Our standard rate of $345.00 per hour.</div>

    <h2 class="section">6. DISBURSEMENTS</h2>
    <div class="clause"><span class="n">6.1</span> Disbursements are not part of Our fee(s). They are payments made to others or expenses incurred by Us on Your behalf, for which We seek reimbursement.</div>
    <div class="clause"><span class="n">6.2</span> We will obtain the following amounts from You as funds in advance, pay the associated disbursements using Our Current (practice) account, and then, following payment, transfer the relevant amounts from Our client fund account to Our Current (practice) account. You will pay the following disbursement(s) in relation to Our service described above:</div>
    <table class="data head">
        <tr><th>Visa Type</th><th class="amount">Cost (NZ$)</th></tr>
        @if(!empty($inz_lines ?? []))
            @foreach($inz_lines as $line)
                <tr><td>{{ $line['label'] }}</td><td class="amount {{ $line['amount'] ? '' : 'fill' }}">{{ $line['amount'] ? ($line['amount'].($line['each'] ? '  (each)' : '')) : '[Amount]' }}</td></tr>
            @endforeach
        @else
            <tr><td class="{{ $visa_category ? '' : 'fill' }}">{{ $visa_category ? ($visa_category.' — INZ Application Fee') : '[INZ Application Fee Description]' }}</td><td class="amount {{ $inz_fee ? '' : 'fill' }}">{{ $inz_fee ?: '[Amount]' }}</td></tr>
        @endif
    </table>
    <p>You are responsible for paying the above fee(s) to INZ directly by bank cheque or credit card, upon lodgement or while on request.</p>
    <div class="clause"><span class="n">6.3</span> Please note that the above disbursement amount is correct as at the date of making this service agreement but may be subject to change at the time of payment.</div>
    <div class="clause"><span class="n">6.4</span> If there are any additional disbursements, We will advise You of these and obtain Your written approval before incurring these costs.</div>

    <h2 class="section">7. PAYMENT TERMS AND CONDITIONS</h2>
    <div class="clause"><span class="n">7.1</span> You will pay Our fees identified above on the following terms: Consulting and service fee – payable upon signing this service agreement (pay in advance). This fee includes all stages outlined in clause 4.2 above.</div>
    <table class="data head">
        <tr><th>In Total</th><th class="amount {{ ($professional_fee_total ?? null) ? '' : 'fill' }}">{{ ($professional_fee_total ?? null) ?: '[Total Fee]' }}</th></tr>
    </table>
    <p class="muted">* Once payment is made, please keep your invoice as your receipt.</p>
    <div class="clause"><span class="n">7.2</span> We will issue invoices to You when fees and/or disbursements become payable in accordance with the above terms.</div>
    <div class="clause"><span class="n">7.3</span> Any additional fees and/or disbursements for which We have obtained Your written approval will be payable by You upon issuance of an invoice.</div>

    <h2 class="section">8. REFUND POLICY</h2>
    <div class="clause"><span class="n">8.1</span> Refunds will be assessed on the basis of what is fair and reasonable in the circumstances. Where a refund is due, it will be paid to You within 20 working days of termination or completion of service.</div>

    <h2 class="section">9. CONFLICTS OF INTEREST</h2>
    <div class="clause"><span class="n">9.1</span> Unless You specifically agree in writing, We cannot represent You if We are aware that there is a potential or actual conflict of interest relating to You, including the existence of any financial or non-financial benefit We will receive as a result of the relationship with You. By signing this service agreement, You note and agree that We may receive a financial or non-financial benefit from the education provider or other third parties (for example, translation services), if applicable.</div>
    <div class="clause"><span class="n">9.2</span> Should an actual conflict of interest mean that either:</div>
    <ul><li>Our objectivity, or the relationship of confidence and trust between Us and You, would be compromised; or</li><li>We would breach Your confidentiality or that of another client,</li></ul>
    <p>We must not, in any circumstances, represent or continue to represent You.</p>
    <div class="clause"><span class="n">9.3</span> If We must stop work on Your Immigration Matter for reasons which You could not reasonably have been aware of, We will undertake a fair and reasonable assessment regarding whether You will be eligible for a refund of any fees paid by You.</div>
    <div class="clause"><span class="n">9.4</span> We are not aware of any actual or potential conflict of interest relating to You.</div>
    <div class="clause"><span class="n">9.5</span> We will advise You in writing as soon as practicable if this changes.</div>

    <h2 class="section">10. COMPLAINTS PROCEDURE</h2>
    <div class="clause"><span class="n">10.1</span> If You have a complaint about Our service, please refer to the copy of Our internal complaints procedure, which has been provided to You and which sets out how You can make a complaint to Us, and the process by which We will respond.</div>

    <h2 class="section">11. CONFIDENTIALITY</h2>
    <div class="clause"><span class="n">11.1</span> We will treat any personal information You give Us as confidential, and keep and maintain such information in accordance with the provisions of the Privacy Act 2020.</div>
    <div class="clause"><span class="n">11.2</span> We will not disclose Your personal information without Your prior consent, except in the following circumstances:</div>
    <ul>
        <li>If making a complaint to the Immigration Advisers Authority relating to another adviser, or reporting an alleged offence under the Immigration Advisers Licensing Act 2007;</li>
        <li>For the administration of the Immigration Advisers Licensing Act 2007; or</li>
        <li>As required by law.</li>
    </ul>
    <div class="clause"><span class="n">11.3</span> Any employees or other people engaged by Us are also required to preserve Your confidentiality.</div>
    <div class="clause"><span class="n">11.4</span> Your personal information will be retained for a period of 7 years, and will be made available to the Immigration Advisers Authority if requested.</div>
    <div class="clause"><span class="n">11.5</span> You have the right to access, and have corrected, any of Your personal information held by Us.</div>

    <h2 class="section">12. CHANGES TO TERMS OF AGREEMENT</h2>
    <div class="clause"><span class="n">12.1</span> Any changes to the terms of this service agreement will be recorded by Us, either as an amendment to this service agreement which will be initialled by You and Us, or as a separate document which will be signed by You and Us and read in conjunction with this service agreement.</div>

    <h2 class="section">13. YOUR OBLIGATIONS TO US</h2>
    <div class="clause"><span class="n">13.1</span> You confirm that:</div>
    <ul>
        <li>You will inform Us of any relevant matters regarding Your immigration status and history;</li>
        <li>You will promptly provide Us with all information and documents We need in order to complete Our service for You, and that all such documents and information will be valid, accurate, complete, and truthful; and</li>
        <li>You will inform Us of any relevant change in Your circumstances that could affect the outcome or delivery of the service You have engaged Us to perform.</li>
    </ul>

    <h2 class="section">14. OUTCOME</h2>
    <div class="clause"><span class="n">14.1</span> We are unable to guarantee the outcome of Your application.</div>
    <div class="clause"><span class="n">14.2</span> We will, however, endeavour to use Our professional skill for Your benefit to the best of Our ability at all times.</div>

    <h2 class="section">15. TERMINATION OF SERVICE AGREEMENT</h2>
    <div class="clause"><span class="n">15.1</span> You are free to terminate this Agreement at any time.</div>
    <div class="clause"><span class="n">15.2</span> We will not terminate Our service to You unless there is good cause for Us to do so. Good cause includes where:</div>
    <ul>
        <li>You breach any of Your obligations to Us;</li>
        <li>You fail to pay any payment due for a period of 20 working days or more; or</li>
        <li>We are unable to carry out Our service because of a change in immigration law or instructions.</li>
    </ul>
    <div class="clause"><span class="n">15.3</span> If Our service to You is terminated, whether on Your instructions or by Our actions, We will confirm this to You in writing.</div>
    <div class="clause"><span class="n">15.4</span> If, for any reason, We cannot continue to act as Your representative, We will take reasonable steps to ensure that Your interests are represented.</div>
    <div class="clause"><span class="n">15.5</span> If either You or We terminate this service agreement under this clause, We will be entitled to be paid a fair and reasonable fee for the work done by Us up until then.</div>

    <h2 class="section">16. INDEPENDENT LEGAL ADVICE</h2>
    <div class="clause"><span class="n">16.1</span> You have the right to seek Your own legal advice about this service agreement.</div>
    <div class="clause"><span class="n">16.2</span> If You would like to obtain Your own legal advice, please do so before You sign this service agreement.</div>

    <h2 class="section">17. SIGNATURES AND ACKNOWLEDGMENT</h2>
    <div class="clause"><span class="n">17.1</span> While signing this service agreement, You acknowledge that:</div>
    <ul>
        <li>We have explained all significant matters, including risks, in the Agreement to You;</li>
        <li>You have read the terms set out above, and agree to them;</li>
        <li>If We have disclosed any actual or potential conflict of interest, including any financial or non-financial benefit We will receive as a result of Our relationship with You, You acknowledge this conflict and agree for Us to act on Your behalf; and</li>
        <li>You have received a copy of the summary of Licensed Immigration Advisers' professional standards and code of conduct, and You have received a copy of the complaints procedure, and We have explained them to You.</li>
    </ul>

    <p style="margin-top:18px;">I have read and understood the terms and conditions set out above.</p>
    <table class="sign-tbl avoid-break">
        <tr>
            <td style="vertical-align:bottom;">
                <div class="sign-line" style="border-bottom:0;"></div>
                <div style="border-bottom:1px solid #374151; text-align:center;">{{ !empty($applicants ?? []) ? implode('; ', array_column($applicants, 'name')) : ($client['name'] ?: '[Full Name of Applicant]') }}</div>
                <div class="sign-cap" style="text-align:center;">(Full Name of Applicant)</div>
            </td>
            <td style="vertical-align:bottom;">
                <div class="sign-line" style="border-bottom:0;"></div>
                <div style="border-bottom:1px solid #374151; text-align:center;">{{ $adviser['name'] }}</div>
                <div class="sign-cap" style="text-align:center;">(Full Name of Immigration Adviser){{ $adviser['licence'] ? ' · Licence '.$adviser['licence'] : '' }}</div>
            </td>
        </tr>
        <tr>
            {{-- Signature sits ON the line and is centred: the wrapper holds
                 the rule as its bottom border, and text-align centres the
                 inline <img> (dompdf-safe — no transforms). --}}
            <td>
                <div style="height:74px; text-align:center; border-bottom:1px solid #374151;">
                    <img id="applicant-signature" src="{{ $client['signature'] ?? '' }}" alt="Signature"
                         style="max-height:70px; max-width:300px; margin-top:2px; display:{{ !empty($client['signature']) ? 'inline' : 'none' }};">
                </div>
                <div class="sign-cap" style="text-align:center;">(Applicant's Signature or Representative's)</div>
            </td>
            <td>
                <div style="height:74px; text-align:center; border-bottom:1px solid #374151;">
                    @if(!empty($adviser['signature']))
                        <img src="{{ $adviser['signature'] }}" alt="Signature" style="max-height:70px; max-width:300px; margin-top:2px;">
                    @endif
                </div>
                <div class="sign-cap" style="text-align:center;">(Immigration Adviser's Signature)</div>
            </td>
        </tr>
    </table>
    <p style="margin-top:14px;">Date: <span class="fill">{{ $generated_date }}</span></p>
    <p class="muted">* Please note You have understood the adviser's working hours: NZ time, Monday–Friday, 10am–5pm. If You have reached Us outside the above working hours, You can leave a message, and We will try Our best to respond as soon as We can.</p>

    @if(!empty($preview))
    {{-- Live signing: the tracker sign modal posts the applicant's drawn
         signature here so it appears on the document in real time. --}}
    <script>
        (function () {
            window.addEventListener('message', function (e) {
                var msg = e.data || {};
                if (msg.type !== 'applicant-signature') return;
                var img = document.getElementById('applicant-signature');
                if (! img) return;
                img.src = msg.value || '';
                // inline (not block) so text-align:center keeps it centred
                img.style.display = msg.value ? 'inline' : 'none';
                if (msg.value) {
                    try { img.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (err) {}
                }
            });
        })();
    </script>
    @endif
@endsection
