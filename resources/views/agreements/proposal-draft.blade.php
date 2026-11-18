<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Study proposal draft</title>
    <style>
        @page { margin: 110px 54px 56px; }
        @font-face { font-family: Urbanist; font-weight: 400; src: url('{{ public_path("fonts/urbanist/Urbanist-Regular.ttf") }}'); }
        @font-face { font-family: Urbanist; font-weight: 700; src: url('{{ public_path("fonts/urbanist/Urbanist-Bold.ttf") }}'); }
        body { font-family: Urbanist, DejaVu Sans, sans-serif; color: #1c2924; font-size: 11pt; line-height: 1.45; }
        header { position: fixed; top: -72px; left: 0; right: 0; border-bottom: 1px solid #436235; padding-bottom: 12px; }
        header img { height: 46px; }
        footer { position: fixed; bottom: -32px; color: #66736c; font-size: 9pt; }
        h1 { font-size: 23pt; margin: 0 0 8px; }
        h2 { font-size: 14pt; color: #436235; margin: 0 0 4px; }
        .muted { color: #66736c; }
        .notice { background: #f0f4ec; padding: 12px; margin: 18px 0; }
        .program { border-bottom: 1px solid #dce3d7; padding: 16px 0; page-break-inside: avoid; overflow-wrap: break-word; }
        p { margin: 6px 0; }
        .reason { margin-top: 12px; white-space: pre-wrap; word-wrap: break-word; }
    </style>
</head>
<body>
    <header><img src="{{ public_path('images/ep-logo.png') }}" alt="EP"></header>
    <footer>EP · Study proposal draft · {{ now()->format('d F Y') }}</footer>
    <h1>Study proposal</h1>
    <p>Prepared for <strong>{{ trim($lead->first_name.' '.$lead->last_name) }}</strong></p>
    <p class="muted">{{ $lead->tracking_code }} · {{ now()->format('d F Y') }}</p>
    <div class="notice">Draft for review. These programme selections have not been approved or sent by downloading this file.</div>
    @foreach ($programs as $program)
        <section class="program">
            <h2>{{ $loop->iteration }}. {{ $program->title }}</h2>
            <p>{{ $program->school?->name ?: ($program->institution ?: 'School to be confirmed') }}</p>
            @if ($program->level)<p>Level {{ $program->level }}</p>@endif
            @if ($program->intake_months)<p><strong>Intake:</strong> {{ $program->intake_months }}</p>@endif
            @if ($program->duration_months)<p><strong>Duration:</strong> {{ $program->duration_months }} months</p>@endif
            @if ($program->price_text)
                <p><strong>Tuition:</strong> {{ $program->price_text }}</p>
            @elseif ((float) $program->tuition_fee > 0)
                <p><strong>Tuition:</strong> NZD {{ number_format((float) $program->tuition_fee, 2) }}</p>
            @endif
            @if (!empty($reasons[$program->id]))
                <p class="reason"><strong>Why this programme:</strong><br>{{ $reasons[$program->id] }}</p>
            @endif
        </section>
    @endforeach
</body>
</html>
