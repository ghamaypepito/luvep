@php
    $couple = $applicant_mode === 'couple';
    $money = fn ($value) => 'Php ' . number_format($value, 2);
    $costs = [
        'School Enrolment and Documentation Fee' => $school_enrolment_fee,
        'English review and unlimited mock tests (optional)' => $english_proficiency_fee,
        'English proficiency examination fee' => $english_proficiency_fee,
        'Medical examination' => $couple ? 29100 : 14550,
        'Licensed immigration adviser fee (approximate)' => $couple ? 108000 : 54000,
        'Immigration New Zealand application fee (approximate)' => $couple ? 90000 : 30600,
        'Travel and medical insurance (approximate)' => $couple ? 69000 : 32500,
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Indicative Cost Breakdown</title>
<style>
    @page { margin: 58px 54px; }
    body { font: 10.5pt/1.45 'DejaVu Sans', sans-serif; color: #14213b; }
    header { border-bottom: 1px solid #436235; padding-bottom: 10px; margin-bottom: 20px; }
    header img { height: 48px; }
    h1 { font-size: 21pt; margin: 0 0 4px; }
    p { margin: 6px 0; }
    .scope { color: #436235; font-weight: bold; }
    .notice { background: #f0f4ec; padding: 10px 12px; margin: 15px 0; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 6px 9px; border-bottom: 1px solid #d9e1d6; text-align: left; }
    thead { background: #436235; color: white; }
    .amount { text-align: right; white-space: nowrap; }
    tfoot { background: #e8f0e2; font-weight: bold; }
    .additional { margin-top: 16px; }
</style>
</head>
<body>
    <header><img src="{{ public_path('images/ep-logo.png') }}" alt="ePathways"></header>
    <h1>Indicative Cost Breakdown</h1>
    <p class="scope">New Zealand Student Visa Application · {{ $couple ? 'Couple' : 'Single' }}</p>
    <p>Prepared for <strong>{{ $client_name }}</strong> · {{ $generated_at->format('d F Y') }}</p>
    <div class="notice">
        These figures are estimates for reference. Costs may vary with exchange rates, providers and the chosen programme.
        Count costs already covered by your package only once.
    </div>
    <table>
        <thead><tr><th scope="col">Cost item</th><th class="amount" scope="col">Amount</th></tr></thead>
        <tbody>
            @foreach ($costs as $label => $value)
                <tr><td>{{ $label }}</td><td class="amount">{{ $money($value) }}</td></tr>
            @endforeach
        </tbody>
        <tfoot><tr><td>Estimated total of listed costs</td><td class="amount">{{ $money(array_sum($costs)) }}</td></tr></tfoot>
    </table>
    <div class="additional">
        <p><strong>Tuition and airfare:</strong> Vary by programme and travel arrangements.</p>
        <p><strong>Living funds (show money):</strong> {{ $money($couple ? 900000 : 720000) }} per year (approximate). Separate from the estimated cost total.</p>
    </div>
</body>
</html>
