<!DOCTYPE html>
<html lang="en-NZ">
<head>
    <meta charset="UTF-8">
    <title>{{ \App\Models\AccommodationRentalInvoice::KINDS[$invoice['kind']] }}</title>
    <style>
        @page { margin: 36px 40px; }
        body { font: 10pt/1.45 DejaVu Sans, sans-serif; color: #132a37; }
        h1 { font-size: 26pt; line-height: 1.15; margin: 10px 0 4px; }
        .brand { color: #155d4c; font-size: 13pt; font-weight: bold; padding-bottom: 8px; border-bottom: 3px solid #277b64; }
        .reference { color: #4b606b; margin: 0 0 18px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { padding: 7px 8px; border: 1px solid #b6c9cb; text-align: left; vertical-align: top; }
        th { background: #123749; color: #fff; }
        .amount { text-align: right; white-space: nowrap; }
        .subtotal td { background: #e6f2ee; font-weight: bold; }
        .total td { background: #20725b; color: #fff; font-size: 14pt; font-weight: bold; }
        .bank { page-break-inside: avoid; }
        .meta td { width: 50%; }
        .period { white-space: nowrap; }
        .line { page-break-inside: avoid; }
    </style>
</head>
<body>
    @php
        $money = static fn ($cents) => 'NZ$'.number_format($cents / 100, 2);
        $date = static fn ($value) => \Carbon\CarbonImmutable::parse($value, 'Pacific/Auckland')->format('j M Y');
    @endphp
    <div class="brand">Exalt Property Management Ltd</div>
    <h1>{{ \App\Models\AccommodationRentalInvoice::KINDS[$invoice['kind']] }}</h1>
    <p class="reference">{{ $reference ?: 'Preview' }} · NZD</p>

    <table class="meta">
        <tr><th>Bill to</th><th>Property</th></tr>
        <tr><td>{{ $invoice['bill_to'] }}</td><td>{{ $invoice['property'] }}</td></tr>
        <tr><th>Invoice date</th><th>Payment due date</th></tr>
        <tr><td>{{ $date($invoice['issue_date']) }}</td><td>{{ $date($invoice['due_date']) }}</td></tr>
    </table>

    @if($invoice['kind'] === 'move-in')
        <table>
            <thead><tr><th>Description</th><th class="amount">Amount (NZD)</th></tr></thead>
            <tbody>
                @foreach($invoice['lines'] as $line)
                    @if($line['group'] === 'move-in')
                        <tr class="line"><td>{{ $line['description'] }}</td><td class="amount">{{ $money($line['amount_cents']) }}</td></tr>
                    @endif
                @endforeach
                <tr class="subtotal"><td>Move-in subtotal</td><td class="amount">{{ $money($invoice['move_in_subtotal']) }}</td></tr>
                @if($invoice['package_subtotal'] > 0)
                    <tr><th colspan="2">Optional Exalt Welcome Packages · service included</th></tr>
                    @foreach($invoice['lines'] as $line)
                        @if($line['group'] === 'package')
                            <tr class="line"><td>{{ $line['description'] }}</td><td class="amount">{{ $money($line['amount_cents']) }}</td></tr>
                        @endif
                    @endforeach
                    <tr class="subtotal"><td>Welcome Packages subtotal</td><td class="amount">{{ $money($invoice['package_subtotal']) }}</td></tr>
                @endif
            </tbody>
        </table>
    @else
        <table>
            <thead><tr><th>Rental period</th><th>Original Friday due date</th><th class="amount">Due</th><th class="amount">Received</th><th class="amount">Outstanding</th><th class="amount">Invoiced</th></tr></thead>
            <tbody>
                @foreach($invoice['weeks'] as $week)
                    <tr class="line">
                        <td class="period">{{ $date($week['start']) }}<br>to {{ $date($week['end']) }}</td>
                        <td>{{ $date($week['original_due']) }}</td>
                        <td class="amount">{{ $money($week['due_cents']) }}</td>
                        <td class="amount">{{ $money($week['received_cents']) }}@if($week['received_on'])<br>{{ $date($week['received_on']) }}@endif</td>
                        <td class="amount">{{ $money($week['outstanding_cents']) }}</td>
                        <td class="amount">{{ $money($week['amount_cents']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="bank">
        <table class="total"><tr><td>Total amount due</td><td class="amount">{{ $money($invoice['total_cents']) }}</td></tr></table>
        <table>
            <tr><th colspan="2">Bank transfer details</th></tr>
            <tr><td>Account name</td><td>{{ $invoice['bank']['name'] }}</td></tr>
            <tr><td>Account number</td><td>{{ $invoice['bank']['account'] }}</td></tr>
        </table>
    </div>
</body>
</html>
