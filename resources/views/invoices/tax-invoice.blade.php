{{--
    Tax Invoice — mirrors the ePathways Migration invoice format
    (TAX INVOICE header, line items, payment details, tear-off PAYMENT
    ADVICE). Rendered to PDF via dompdf and to HTML for the live preview.

    Vars: $logo_data, $company, $client, $invoice, $items, $total, $bank
--}}
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Tax Invoice {{ $invoice['number'] }} — {{ $company['name'] }}</title>
<style>
    @font-face { font-family: 'Urbanist'; font-weight: 400; src: url('{{ base_path("resources/fonts/urbanist/Urbanist-Regular.ttf") }}') format('truetype'); }
    @font-face { font-family: 'Urbanist'; font-weight: 700; src: url('{{ base_path("resources/fonts/urbanist/Urbanist-Bold.ttf") }}') format('truetype'); }

    @page { margin: 46px 48px 40px 48px; }
    * { box-sizing: border-box; }
    body { font-family: 'Urbanist', DejaVu Sans, sans-serif; font-size: 10pt; color: #1a1a1a; line-height: 1.45; margin: 0; }

    /* ---- Masthead ---- */
    .masthead { width: 100%; margin-bottom: 18px; }
    .masthead td { vertical-align: top; }
    .logo-cell { text-align: right; }
    /* Explicit width — dompdf can ignore height-only sizing and render the
       source image at full size, which blows the page out. Sized to the
       immigration logo's trimmed 3.17:1 aspect ratio so it isn't stretched. */
    .logo-cell img { width: 130px; height: 41px; }

    h1.doc-title { font-size: 26pt; font-weight: 400; letter-spacing: -0.5px; margin: 0 0 6px 0; }
    .bill-to { font-size: 10.5pt; margin-left: 40px; }

    .meta { font-size: 9.5pt; }
    .meta .label { font-weight: 700; margin-top: 8px; }
    .meta .value { margin-bottom: 2px; }
    .company-block { font-size: 10pt; line-height: 1.5; }

    /* ---- Line items ---- */
    table.items { width: 100%; border-collapse: collapse; margin-top: 24px; }
    table.items th { font-size: 9pt; font-weight: 700; text-align: left; padding: 0 8px 6px 8px; border-bottom: 1px solid #333; }
    table.items th.num, table.items td.num { text-align: right; }
    table.items td { padding: 12px 8px; border-bottom: 1px solid #e2e2e2; font-size: 9.5pt; vertical-align: top; }
    table.items tr.total td { border-bottom: 0; border-top: 1px solid #333; font-weight: 700; font-size: 10pt; padding-top: 10px; }
    table.items tr.subtotal td { border-bottom: 0; border-top: 1px solid #999; font-weight: 700; font-size: 9.5pt; padding-top: 8px; }
    /* Per-applicant section heading on a family invoice. */
    .applicant-head { font-size: 10pt; font-weight: 700; margin: 22px 0 0 0; color: #0f766e; }
    .applicant-head .visa { font-weight: 400; color: #555; }

    /* ---- Payment block ---- */
    .pay { margin-top: 24px; font-size: 9.5pt; }
    .pay .due { font-weight: 700; font-size: 11pt; margin-bottom: 4px; }
    .pay .line { margin: 1px 0; }
    .pay .note { font-size: 8.5pt; margin-top: 6px; }
    .paylink { color: #0b6ec9; font-weight: 700; text-decoration: underline; font-size: 10pt; margin-top: 14px; }

    /* ---- Tear-off payment advice ---- */
    .tear { border-top: 1px dashed #999; margin-top: 30px; padding-top: 16px; }
    h2.advice-title { font-size: 20pt; font-weight: 400; margin: 0; }
    table.advice { width: 100%; margin-top: 4px; }
    table.advice td { vertical-align: top; font-size: 9.5pt; }
    table.advice .to { padding-left: 26px; }
    table.adv-fields { width: 100%; border-collapse: collapse; }
    table.adv-fields td { padding: 3px 0; font-size: 9.5pt; }
    table.adv-fields td.k { width: 45%; }
    table.adv-fields tr.rule td { border-bottom: 1px solid #333; padding-bottom: 6px; }
    .enclosed-hint { font-size: 8pt; text-align: right; margin-top: 3px; }
</style>

@if(!empty($preview))
{{-- On-screen preview: frame as an A4 sheet so it reads like the print. --}}
<style>
    html { background: #e5e7eb; margin: 0; padding: 0; }
    body {
        width: 794px; min-height: 1123px; margin: 24px auto;
        padding: 46px 48px 40px 48px; background: #fff;
        box-shadow: 0 2px 14px rgba(0,0,0,0.14);
    }
</style>
@endif
</head>
<body>

{{-- ---------- MASTHEAD ---------- --}}
<table class="masthead">
    <tr>
        <td style="width: 55%;"></td>
        <td class="logo-cell"><img src="{{ $logo_data }}" alt="{{ $company['name'] }}"></td>
    </tr>
</table>

<table class="masthead" style="margin-bottom: 0;">
    <tr>
        <td style="width: 46%;">
            <h1 class="doc-title">TAX INVOICE</h1>
            <div class="bill-to">{{ $client['name'] }}</div>
        </td>
        <td style="width: 27%;" class="meta">
            <div class="label">Invoice Date</div>
            <div class="value">{{ $invoice['date'] }}</div>
            <div class="label">Invoice Number</div>
            <div class="value">{{ $invoice['number'] }}</div>
            <div class="label">GST Number</div>
            <div class="value">{{ $company['gst'] }}</div>
        </td>
        <td style="width: 27%;" class="company-block">
            {{ $company['name'] }}<br>
            {{ $company['address_1'] }}<br>
            {{ $company['address_2'] }}<br>
            {{ $company['country'] }}
        </td>
    </tr>
</table>

{{-- ---------- LINE ITEMS ---------- --}}
@if(!empty($groups))
    {{-- Family invoice: one section per applicant (principal, partner, then
        children), each with its own consulting fee + disbursement and subtotal,
        followed by a single grand total. --}}
    @foreach($groups as $g)
        <div class="applicant-head">{{ $g['name'] }} <span class="visa">— {{ $g['visa'] }}</span></div>
        <table class="items" style="margin-top: 4px;">
            <thead>
                <tr>
                    <th style="width: 58%;">Description</th>
                    <th class="num" style="width: 12%;">Quantity</th>
                    <th class="num" style="width: 15%;">Unit Price</th>
                    <th class="num" style="width: 15%;">Amount NZD</th>
                </tr>
            </thead>
            <tbody>
                @foreach($g['items'] as $item)
                    <tr>
                        <td>{{ $item['description'] }}</td>
                        <td class="num">{{ number_format($item['quantity'], 2) }}</td>
                        <td class="num">{{ number_format($item['unit_price'], 2) }}</td>
                        <td class="num">{{ number_format($item['amount'], 2) }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal">
                    <td></td>
                    <td></td>
                    <td class="num">Subtotal NZD</td>
                    <td class="num">{{ number_format($g['subtotal'], 2) }}</td>
                </tr>
            </tbody>
        </table>
    @endforeach
    <table class="items" style="margin-top: 8px;">
        <tbody>
            <tr class="total">
                <td style="width: 58%;"></td>
                <td style="width: 12%;"></td>
                <td class="num" style="width: 15%;">TOTAL NZD</td>
                <td class="num" style="width: 15%;">{{ number_format($total, 2) }}</td>
            </tr>
        </tbody>
    </table>
@else
    <table class="items">
        <thead>
            <tr>
                <th style="width: 58%;">Description</th>
                <th class="num" style="width: 12%;">Quantity</th>
                <th class="num" style="width: 15%;">Unit Price</th>
                <th class="num" style="width: 15%;">Amount NZD</th>
            </tr>
        </thead>
        <tbody>
            @foreach($items as $item)
                <tr>
                    <td>{{ $item['description'] }}</td>
                    <td class="num">{{ number_format($item['quantity'], 2) }}</td>
                    <td class="num">{{ number_format($item['unit_price'], 2) }}</td>
                    <td class="num">{{ number_format($item['amount'], 2) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td></td>
                <td></td>
                <td class="num">TOTAL NZD</td>
                <td class="num">{{ number_format($total, 2) }}</td>
            </tr>
        </tbody>
    </table>
@endif

{{-- ---------- PAYMENT DETAILS ---------- --}}
<div class="pay">
    <div class="due">Due Date: {{ $invoice['due_date'] }}</div>
    <div class="line">Please direct your payment to:</div>
    <div class="line">Account Name: {{ $bank['account_name'] }}</div>
    <div class="line">Account Number: {{ $bank['account_number'] }}</div>
    <div class="line">Bank Name: {{ $bank['bank_name'] }}</div>
    <div class="line">Bank Address: {{ $bank['bank_address'] }}</div>
    <div class="line">Swift Code: {{ $bank['swift'] }}</div>
    <div class="note">*Please note for overseas telegraphic transfer, you are responsible for the bank charges</div>
    @if(!empty($invoice['pay_url']))
        <div class="paylink">View and pay online now</div>
    @endif
</div>

{{-- ---------- PAYMENT ADVICE (tear-off) ---------- --}}
<div class="tear">
    <table class="advice">
        <tr>
            <td style="width: 52%;">
                <h2 class="advice-title">PAYMENT ADVICE</h2>
                <div class="to" style="margin-top: 10px;">
                    To:&nbsp; {{ $company['name'] }}<br>
                    <span style="margin-left: 26px;">{{ $company['address_1'] }}</span><br>
                    <span style="margin-left: 26px;">{{ $company['address_2'] }}</span><br>
                    <span style="margin-left: 26px;">{{ $company['country'] }}</span>
                </div>
            </td>
            <td style="width: 48%;">
                <table class="adv-fields">
                    <tr class="rule"><td class="k">Customer</td><td>{{ $client['name'] }}</td></tr>
                    <tr class="rule"><td class="k">Invoice Number</td><td>{{ $invoice['number'] }}</td></tr>
                    <tr class="rule"><td class="k"><strong>Amount Due</strong></td><td><strong>{{ number_format($total, 2) }}</strong></td></tr>
                    <tr class="rule"><td class="k"><strong>Due Date</strong></td><td><strong>{{ $invoice['due_date'] }}</strong></td></tr>
                    <tr class="rule"><td class="k">Amount Enclosed</td><td></td></tr>
                </table>
                <div class="enclosed-hint">Enter the amount you are paying above</div>
            </td>
        </tr>
    </table>
</div>

</body>
</html>
