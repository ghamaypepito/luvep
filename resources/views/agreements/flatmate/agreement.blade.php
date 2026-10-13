@extends('agreements.flatmate.layout', ['doc_title' => $titles[$agreementType] ?? 'Flat/House Sharing Agreement'])

@php
    // Light placeholder helper: show the value, or a greyed hint when empty.
    $f = fn ($v, $hint = '—') => trim((string) $v) !== '' ? e($v) : '<span class="fill">'.e($hint).'</span>';

    // Money helper: format a numeric amount as 1,234.56; otherwise show raw / hint.
    $m = function ($v, $hint = '0.00') {
        $clean = str_replace([',', ' '], '', (string) $v);
        if (is_numeric($clean)) {
            return number_format((float) $clean, 2);
        }

        return trim((string) $v) !== '' ? e($v) : '<span class="fill">'.e($hint).'</span>';
    };

    // Date helper: format a date with the month spelled out (30 September 2026);
    // falls back to the raw value if it isn't parseable, or a hint when empty.
    $d = function ($v, $hint = '—') {
        $v = trim((string) $v);
        if ($v === '') {
            return '<span class="fill">'.e($hint).'</span>';
        }
        try {
            return \Illuminate\Support\Carbon::parse($v)->format('j F Y');
        } catch (\Throwable $e) {
            return e($v);
        }
    };

    $isTransient = $agreementType === 'transient_flatmate';
    $title = $titles[$agreementType] ?? 'Flat/House Sharing Agreement';

    $t = $data['tenancy'] ?? [];
    $c = $data['costs'] ?? [];
    $bank = $data['bank'] ?? [];
    $subtenants = $data['subtenants'] ?? [];
    // Always show at least 3 sub-tenant blocks like the source template.
    $slots = max(3, count($subtenants));
@endphp

@section('body')
    <div class="doc-title">{{ $title }}</div>
    <p class="prop-line">PROPERTY ADDRESS: <span class="val">{!! $f($data['property_address'] ?? '', '[Full property address]') !!}</span></p>
    @if($isTransient)
        <p class="note-line">Exalt Terms and Conditions and the most recent House Rules are attached as appendices.</p>
    @endif

    {{-- ---------- Main tenant (Exalt) ---------- --}}
    <h3 class="blk">Main Tenant [Exalt Property] Details</h3>
    <table class="kv">
        <tr><td class="label">Name</td><td>EXALT PROPERTY MANAGEMENT LTD</td></tr>
        <tr><td class="label">Address of service</td><td>21 Vazey Way, Hobsonville, Auckland 0618</td></tr>
        <tr><td class="label">Contact person</td><td>Exalt Property Admin — Alane Beryl Lozada</td></tr>
        <tr><td class="label">Email</td><td>exaltinfo@luvep.com</td></tr>
        <tr><td class="label">Mobile No</td><td>+64 21 227 8999 [Exalt Admin]</td></tr>
        <tr><td class="label">Other contacts (WhatsApp)</td><td>+64 27 777 5586 [Admin]</td></tr>
    </table>

    {{-- ---------- Sub-tenants ---------- --}}
    @for($i = 0; $i < $slots; $i++)
        @php $s = $subtenants[$i] ?? []; @endphp
        <div class="subt-head avoid-break">Sub-Tenant {{ $i + 1 }} Details</div>
        <table class="kv avoid-break">
            <tr>
                <td class="label">First Name</td><td>{!! $f($s['first_name'] ?? '') !!}</td>
                <td class="label">Last Name</td><td>{!! $f($s['last_name'] ?? '') !!}</td>
            </tr>
            <tr>
                <td class="label">Passport / ID Number</td><td>{!! $f($s['passport_id'] ?? '') !!}</td>
                <td class="label">Expiry Date</td><td>{!! $d($s['expiry_date'] ?? '') !!}</td>
            </tr>
            <tr><td class="label">Date of Birth</td><td colspan="3">{!! $d($s['dob'] ?? '') !!}</td></tr>
            <tr><td class="label">Current Address</td><td colspan="3">{!! $f($s['current_address'] ?? '') !!}</td></tr>
            <tr><td class="label">Email</td><td colspan="3">{!! $f($s['email'] ?? '') !!}</td></tr>
            <tr><td class="label">Mobile No / WhatsApp</td><td colspan="3">{!! $f($s['mobile'] ?? '') !!}</td></tr>
            @if($i > 0)
                <tr><td class="label">Relationship to Sub-Tenant 1</td><td colspan="3">{!! $f($s['relationship'] ?? '') !!}</td></tr>
            @endif
        </table>
    @endfor

    {{-- ---------- Move-in details ---------- --}}
    <h2 class="section">Move-In Details</h2>
    <p>Exalt and Tenant agree that:</p>
    <p class="clause"><span class="n">1.</span> This flat/house sharing agreement shall commence on
        <strong>{!! $d($t['commence_date'] ?? '', 'DD/MM/YYYY') !!}</strong> and will last for:</p>

    <table class="grid">
        <tr><th style="width:35%">Duration of Stay</th><th>Date</th></tr>
        @if($isTransient)
            <tr><td>Confirmed Stay</td><td>{!! $f($t['term'] ?? '', '[Number of days / weeks / months]') !!} until {!! $d($t['end_date'] ?? '', 'DD/MM/YYYY') !!}</td></tr>
        @else
            <tr><td>Fixed Term</td><td>{!! $f($t['term'] ?? '', '[Number of months]') !!} until {!! $d($t['end_date'] ?? '', 'DD/MM/YYYY') !!}</td></tr>
        @endif
    </table>

    @if($isTransient)
        <p class="clause"><span class="n">2.</span> Tick one:</p>
        <p>
            <span class="tick">{{ empty($t['room_shared']) ? '☑' : '☐' }}</span> The room allocated to the tenant is <strong>not shared</strong> with other tenants.<br>
            <span class="tick">{{ !empty($t['room_shared']) ? '☑' : '☐' }}</span> The room allocated to the tenant <strong>is shared</strong> with other tenants, with a maximum of {!! $f($t['room_shared_max'] ?? '', '[number]') !!} other tenant(s).
        </p>
        <p>The tenant's confirmed accommodation period is {!! $f($t['term'] ?? '', '[number of days / weeks / months]') !!}.</p>
        <p>The agreed accommodation arrangement includes the Tenant's accommodation, rent, and the utilities specified below for the confirmed accommodation period.</p>
        <p><strong>Included Utilities:</strong> Power, water, internet, and gas (where applicable).</p>
    @else
        <p>The Tenant's agreed accommodation period is {!! $f($t['term'] ?? '', '[number of months]') !!}.</p>
    @endif

    <p>The agreed move-in time is {!! $f($t['move_in_time'] ?? '', '[time]') !!} on {!! $d($t['move_in_date'] ?? '', '[date]') !!}, and the agreed move-out time is {!! $f($t['move_out_time'] ?? '', '[time]') !!} on {!! $d($t['move_out_date'] ?? '', '[date]') !!}.</p>

    <h3 class="blk">Move-in Cost</h3>
    <table class="grid">
        <tr><th style="width:50%">Item</th><th>Period</th><th class="amt">Amount</th></tr>
        @if($isTransient)
            <tr><td>Bond (rent + utilities)</td><td>1 week</td><td class="amt">NZ${!! $m($c['bond'] ?? '') !!}</td></tr>
            <tr><td>Deposit (rent + utilities)</td><td>1 week</td><td class="amt">NZ${!! $m($c['deposit'] ?? '') !!}</td></tr>
            <tr><td>Rent + Utilities in Advance</td><td>{!! $f($t['term'] ?? '', '[confirmed stay]') !!}</td><td class="amt">NZ${!! $m($c['rent_advance'] ?? '') !!}</td></tr>
            <tr><td>Pro-rated Rent + Utilities</td><td>{!! $f($c['prorated_days'] ?? '', '[days, if applicable]') !!}</td><td class="amt">NZ${!! $m($c['prorated_rent'] ?? '') !!}</td></tr>
        @else
            <tr><td>Bond</td><td>1 week</td><td class="amt">NZ${!! $m($c['bond'] ?? '') !!}</td></tr>
            <tr><td>Deposit</td><td>1 week</td><td class="amt">NZ${!! $m($c['deposit'] ?? '') !!}</td></tr>
            <tr><td>Rent in Advance</td><td>1 week</td><td class="amt">NZ${!! $m($c['rent_advance'] ?? '') !!}</td></tr>
            <tr><td>Pro-rated Rent</td><td>{!! $f($c['prorated_days'] ?? '', '[number of days]') !!}</td><td class="amt">NZ${!! $m($c['prorated_rent'] ?? '') !!}</td></tr>
        @endif
        <tr class="total"><td colspan="2">Total Move-in Cost</td><td class="amt">NZ${!! $m($c['total_move_in'] ?? '') !!}</td></tr>
    </table>

    @if($isTransient)
        <p>The rent + utilities in advance covers the confirmed accommodation period stated above. Exalt's standard rental week runs from Monday to Sunday. Where the Tenant's move-in date falls partway through the standard rental week, the applicable amount will be calculated on a pro-rated basis for the relevant number of days.</p>
    @else
        <p>The rent in advance covers the period from {!! $d($c['rent_advance_from'] ?? '', '[Monday, date]') !!} to {!! $d($c['rent_advance_to'] ?? '', '[Sunday, date]') !!}. Where the Tenant's move-in date falls partway through Exalt's standard rental week, a pro-rated rent amount will apply for the applicable number of days. Please note that Exalt's standard rental week runs from Monday to Sunday, and rent is payable in advance in accordance with the payment schedule stated in the Agreement.</p>
        <p>The bond and deposit are calculated based on the agreed weekly rent and are refundable subject to the terms and conditions of the Agreement, completion of the final inspection, and settlement of any outstanding amounts or damages.</p>

        {{-- Utilities selection — only the long-term / whole-property forms carry this. --}}
        <h2 class="section">Utilities and Bill Payment</h2>
        <p>Utilities are not included in the rent. The Tenant must select one of the following utility arrangements:</p>
        <p><strong>Option 1 — Exalt Manages Utilities.</strong> The Tenant authorises Exalt Property Management to manage the applicable utility bills for the Property. Once Exalt receives a payable utility bill, Exalt will provide the relevant bill or payment amount to the Tenant, who must pay the full amount by the applicable due date.</p>
        <p><strong>Option 2 — Tenant Arranges Utilities Directly.</strong> The Tenant is responsible for arranging and maintaining their own utility accounts with the relevant providers and paying the applicable costs directly by their due dates.</p>
        <p>Selected Utility Arrangement:<br>
            <span class="tick">{{ ($t['utility_option'] ?? '') == '1' ? '☑' : '☐' }}</span> Option 1 — Exalt Manages Utilities @if(($t['utility_option'] ?? '') == '1' && ! empty($t['utility_amount'])) &nbsp;—&nbsp; <strong>NZ${!! $m($t['utility_amount']) !!} / week</strong>@endif<br>
            <span class="tick">{{ ($t['utility_option'] ?? '') == '2' ? '☑' : '☐' }}</span> Option 2 — Tenant Arranges Utilities Directly @if(($t['utility_option'] ?? '') == '2' && ! empty($t['utility_amount'])) &nbsp;—&nbsp; <strong>NZ${!! $m($t['utility_amount']) !!} / week</strong>@endif
        </p>
    @endif

    <h3 class="blk">Weekly Rent{{ $isTransient ? ' + Utilities' : '' }} Payable</h3>
    <table class="grid">
        <tr><th style="width:60%">Item</th><th class="amt">Amount</th></tr>
        <tr><td>Weekly Rent — payable in advance every {!! $f($t['rent_day'] ?? 'Friday', 'Friday') !!}</td><td class="amt">NZ${!! $m($t['weekly_rent'] ?? '') !!}</td></tr>
        <tr class="total"><td>Total Weekly {{ $isTransient ? 'Expenses' : 'Rent' }}</td><td class="amt">NZ${!! $m($t['weekly_rent'] ?? '') !!}</td></tr>
    </table>
    <p class="callout">Weekly rent is due every {{ $t['rent_day'] ?? 'Friday' }}. The payment made each {{ $t['rent_day'] ?? 'Friday' }} is for the upcoming rental week.
        @unless($isTransient) Under Option 1, applicable utility costs are payable separately from the weekly rent and an administration fee applies. Under Option 2, the Tenant pays the utility providers directly.@endunless
    </p>

    {{-- ---------- Bank ---------- --}}
    <h3 class="blk">Exalt Property Bank Details</h3>
    <table class="kv">
        <tr><td class="label">Account name</td><td>Exalt Property Management Limited</td></tr>
        <tr><td class="label">Account No</td><td>01-0288-0332767-00</td></tr>
        <tr><td class="label">Particular</td><td>{!! $f($bank['particular'] ?? '', '[Tenant name]') !!}</td></tr>
        <tr><td class="label">Code</td><td>RENTUTIL / RENT</td></tr>
        <tr><td class="label">Reference</td><td>{!! $f($bank['reference'] ?? '', '[Property name]') !!}</td></tr>
    </table>

    {{-- ---------- Signatures (sub-tenants + Exalt Property Management) ---------- --}}
    @php $signature = $signature ?? null; $signedMeta = $signedMeta ?? null; $adminSignature = $adminSignature ?? null; $adminName = $adminName ?? null; @endphp
    <h2 class="section">Signatures</h2>
    <p>Do not sign this agreement unless you understand and agree with everything in it. Please check the terms and conditions carefully. Exalt and Tenant sign here to show that they agree to all the terms and conditions in the tenancy agreement.</p>
    <table class="sign">
        <tr>
            <td>
                @if($signature)
                    <img src="{{ $signature }}" alt="Signature" style="max-height:48px; display:block; margin-bottom:2px;">
                @endif
                <div class="sign-line"></div>
                <div class="sign-cap">Sub-Tenant 1 &nbsp;·&nbsp; {{ $signedMeta['signed_at'] ?? 'Date' }}</div>
            </td>
            <td><div class="sign-line"></div><div class="sign-cap">Sub-Tenant 2 &nbsp;·&nbsp; Date</div></td>
        </tr>
        <tr>
            <td><div class="sign-line"></div><div class="sign-cap">Exalt Property Management &nbsp;·&nbsp; Date</div></td>
            <td></td>
        </tr>
    </table>

    @if($signedMeta)
        <div class="callout" style="margin-top:14px;">
            <strong>Electronically signed.</strong>
            Signed by {{ $signedMeta['signer_name'] }} on {{ $signedMeta['signed_at'] }}.
            @if(!empty($signedMeta['signer_ip'])) IP {{ $signedMeta['signer_ip'] }}.@endif
            This audit record is embedded in the signed document.
        </div>
    @endif

    {{-- ---------- Appendices (identical across all three agreement types) ---------- --}}
    <div class="appendix">@include('agreements.flatmate._terms')</div>
    <div class="appendix">@include('agreements.flatmate._house_rules')</div>
    <div class="appendix">@include('agreements.flatmate._declaration')</div>

    {{-- ---------- Exalt Property Admin signatory (at the very end, after the Declaration) ---------- --}}
    <table class="sign">
        <tr>
            <td>
                @if($adminSignature)
                    <img src="{{ $adminSignature }}" alt="Exalt Property Admin signature" style="max-height:48px; display:block; margin-bottom:2px;">
                @endif
                <div class="sign-line"></div>
                <div class="sign-cap">Exalt Property Admin</div>
                <div class="decl-cap">[signatory of admin]</div>
            </td>
            <td></td>
        </tr>
    </table>
@endsection
