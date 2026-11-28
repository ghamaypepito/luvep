<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Exalt countersignature</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1f2937; margin: 52px; font-size: 11pt; }
        img { max-width: 260px; max-height: 80px; margin: 18px 0 8px; }
        h1 { color: #1f5685; }
        .reference { font-size: 9pt; overflow-wrap: anywhere; }
    </style>
</head>
<body>
    <h1>Exalt Property Management countersignature</h1>
    <p>{{ $agreement->typeLabel() }} · Agreement #{{ $agreement->id }}</p>
    <p>{{ $agreement->client_name }} · {{ $agreement->property_address }}</p>
    <p>I countersign on behalf of Exalt Property Management Ltd and agree to the terms of the client-signed agreement reproduced in the preceding pages.</p>
    <img src="{{ $signature }}" alt="Exalt signature">
    <p>{{ $name }}<br>{{ $signedAt }}</p>
    <p class="reference">Original client-signed PDF SHA-256<br>{{ $hash }}</p>
</body>
</html>
