<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Exalt viewing attendance</title>
</head>
<body style="font:16px system-ui;color:#153c35;background:#eaf2ef;margin:0;padding:2rem">
    <main style="background:white;max-width:30rem;margin:3rem auto;padding:2rem;border-radius:.75rem">
        <h1>Viewing attendance</h1>
        @if ($saved)
            <p>Thanks. Exalt has recorded that you {{ $response === 'confirmed' ? 'can' : 'cannot' }} attend.</p>
        @else
            <p>Tell Exalt that you {{ $response === 'confirmed' ? 'can' : 'cannot' }} attend this viewing.</p>
            <form method="post" action="{{ request()->fullUrl() }}">
                @csrf
                <button type="submit" style="padding:.75rem;background:#153c35;color:white;border:0;border-radius:.5rem">
                    {{ $response === 'confirmed' ? 'Confirm Attendance' : 'Decline Attendance' }}
                </button>
            </form>
        @endif
    </main>
</body>
</html>
