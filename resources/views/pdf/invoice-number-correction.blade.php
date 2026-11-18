<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>body { font-family: DejaVu Sans, sans-serif; margin: 48px; font-size: 12px; line-height: 1.65; } h1 { font-size: 26px; margin-bottom: 10px; } dl { padding: 18px; background: #eef4f3; } dt { font-weight: bold; margin-top: 8px; } dd { margin-left: 0; }</style>
</head>
<body>
    <h1>Replacement invoice {{ $number }}</h1>
    <p>Invoice number correction</p>
    <dl>
        <dt>Replacement issued</dt><dd>{{ $date }}</dd>
        <dt>Supersedes invoice</dt><dd>{{ $previous }}</dd>
        <dt>Number on the original invoice pages</dt><dd>{{ $original }}</dd>
    </dl>
    <p>Use {{ $number }} as the invoice number and for future correspondence and transfers. This replacement applies to the same supply and preserves its original line items, tax, total, due date and payments already received.</p>
    <p>The original invoice pages follow for reference. Use your current statement for the outstanding balance.</p>
</body>
</html>
