<!doctype html>
<html lang="en-NZ">
<head>
    <meta charset="utf-8">
    <title>Purchase invoice {{ $reference }}</title>
    <style>
        body { font: 12px DejaVu Sans, sans-serif; color: #202a36; margin: 32px; }
        h1 { font-size: 24px; margin: 12px 0 22px; }
        h2 { font-size: 14px; margin: 24px 0 8px; }
        .seller { font-size: 15px; font-weight: bold; color: #1f5a8b; }
        .meta { margin: 12px 0; }
        .meta div { margin: 4px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; border-bottom: 1px solid #d8e0e8; padding: 8px; }
        th { background: #1f5a8b; color: white; }
        td.amount, th.amount { text-align: right; white-space: nowrap; }
        .summary { margin-top: 10px; text-align: right; }
        .instructions { white-space: pre-wrap; overflow-wrap: break-word; }
    </style>
</head>
<body>
    <div class="seller">Exalt Property Management Ltd</div>
    <div>exaltinfo@luvep.com</div>
    @if (filled($invoice['gst_number'] ?? null))
        <div>GST number {{ $invoice['gst_number'] }}</div>
    @endif
    <h1>Purchase invoice {{ $reference }}</h1>
    <div class="meta">
        <div><strong>Bill to</strong> {{ $invoice['bill_to'] }}</div>
        <div><strong>Property</strong> {{ $propertyAddress }}</div>
        <div><strong>Issue date</strong> {{ $invoice['issue_date'] }}</div>
        @if (filled($invoice['due_date'] ?? null))
            <div><strong>Due date</strong> {{ $invoice['due_date'] }}</div>
        @endif
    </div>
    <table>
        <thead><tr><th>Purchase or service</th><th class="amount">Amount (NZD)</th></tr></thead>
        <tbody>
            @foreach ($invoice['lines'] as $line)
                <tr><td>{{ $line['description'] }}</td><td class="amount">{{ number_format((float) $line['amount'], 2) }}</td></tr>
            @endforeach
        </tbody>
    </table>
    <p class="summary"><strong>Total due NZD {{ number_format((float) $invoice['total'], 2) }}</strong></p>
    <p class="summary">GST charged by Exalt included in total NZD {{ number_format((float) $invoice['gst'], 2) }}</p>
    <h2>Payment instructions</h2>
    <div class="instructions">{{ $invoice['payment_instructions'] }}</div>
</body>
</html>
