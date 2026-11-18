<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Education email preferences · Luvep</title>
    <style>
        body { margin: 0; padding: 24px; background: #eef2eb; color: #203127; font: 16px/1.55 system-ui, sans-serif; }
        main { max-width: 520px; margin: 5vh auto; padding: clamp(24px, 5vw, 44px); background: white; border-radius: 20px; }
        h1 { line-height: 1.2; } a { color: inherit; }
        label { display: flex; gap: 12px; align-items: baseline; margin: 16px 0; }
        input { accent-color: #28613f; }
        button { padding: 13px 18px; margin-top: 8px; border: 0; border-radius: 10px; background: #28613f; color: #fff; font: inherit; cursor: pointer; }
        @media (prefers-color-scheme: dark) {
            body { background: #18221b; color: #edf3ed; }
            main { background: #243229; }
            button { background: #a6d2af; color: #18221b; }
        }
    </style>
</head>
<body>
<main>
    <h1>{{ $saved ? 'Your choices are saved' : 'Choose your Education emails' }}</h1>
    @if ($saved)
        <p>Thanks for confirming. You can unsubscribe using the link in any marketing email.</p>
        <p><a href="{{ route('education.register') }}">Back to Luvep Education</a></p>
    @else
        <p>Choose which updates you’d like from Luvep Education. You can unsubscribe at any time.</p>
        @if ($errors->any())
            <p role="alert">{{ $errors->first() }}</p>
        @endif
        <form method="post">
            @csrf
            <label><input type="checkbox" name="follow_up" value="1" @checked($choices['follow_up'])> Email me study tips and adviser follow-ups after my event.</label>
            <label><input type="checkbox" name="newsletter" value="1" @checked($choices['newsletter'])> Subscribe me to the Luvep Education newsletter.</label>
            <button type="submit">Confirm my choices</button>
        </form>
    @endif
</main>
</body>
</html>
