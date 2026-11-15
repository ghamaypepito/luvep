<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { font: 12pt "DejaVu Sans", sans-serif; color: #111827; margin: 32pt; }
        h1 { font-size: 18pt; }
        img { max-width: 210pt; max-height: 110pt; }
        .reference { color: #4b5563; font-size: 8pt; word-wrap: break-word; }
    </style>
</head>
<body>
    <h1>Client signature confirmation</h1>
    <p>I have read and agree to the terms of the agreement on the preceding pages.</p>
    <p><strong>Signed by</strong> {{ $name }}</p>
    <p><strong>Signed on</strong> {{ $signedAt }}</p>
    <img src="{{ $signature }}" alt="Client signature">
    <p class="reference">Issued PDF SHA-256: {{ $hash }}</p>
</body>
</html>
