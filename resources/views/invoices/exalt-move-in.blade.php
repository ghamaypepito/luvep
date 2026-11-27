<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Exalt move-in invoice</title>
    <style>
        body { font: 11pt/1.5 DejaVu Sans, sans-serif; color: #1f2937; }
        h1 { color: #1f5a8b; margin-bottom: 4px; }
        table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        th, td { border-bottom: 1px solid #d1d5db; padding: 8px; text-align: left; }
        .amount { text-align: right; white-space: nowrap; }
        .total { background: #eaf1f8; font-weight: bold; }
    </style>
</head>
<body>
    <strong>Exalt Property Management Ltd</strong>
    <h1>Move-in invoice</h1>
    <div>Invoice reference: EXALT-MI-{{ $agreement->id }}</div>
    <div>Agreement issued: {{ $agreement->sent_at?->timezone('Pacific/Auckland')->format('j F Y') }}</div>
    <div>Client: {{ $agreement->client_name }}</div>
    <div>Property: {{ $agreement->property_address }}</div>
    <table>
        <thead><tr><th>Description</th><th class="amount">Amount (NZD)</th></tr></thead>
        <tbody>
            @foreach($invoice['lines'] as $line)
                <tr><td>{{ $line['description'] }}</td><td class="amount">${{ number_format((float) $line['amount'], 2) }}</td></tr>
            @endforeach
            @if((float) $invoice['package_subtotal'] > 0)
                <tr><td>Move-in cost subtotal</td><td class="amount">${{ number_format((float) $invoice['move_in_subtotal'], 2) }}</td></tr>
                <tr><td>Welcome Packages subtotal (service included)</td><td class="amount">${{ number_format((float) $invoice['package_subtotal'], 2) }}</td></tr>
            @endif
            <tr class="total"><td>Total amount payable</td><td class="amount">${{ number_format((float) $invoice['total'], 2) }}</td></tr>
        </tbody>
    </table>
</body>
</html>
