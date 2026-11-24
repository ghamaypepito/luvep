<x-mail::message>
# Your {{ $nounTitle }} is ready, {{ $firstName }}

@if($documentOnly ?? false)
Your English agreement is ready. Use the private document link below to review and sign it — no portal password is required. This link is valid for seven days.
@else
We've prepared your {{ $noun }} and it's waiting on your personal tracker. Open the link below to review it — no login required.

Through your tracker you can:

- Review the {{ $noun }} we prepared for you
- Download or share it as a PDF
- See your overall application progress
- Reach out if anything needs adjusting
@endif

<x-mail::button :url="$trackUrl">
{{ ($documentOnly ?? false) ? 'Open English agreement' : 'Open my tracker' }}
</x-mail::button>

If the button doesn't work, copy and paste this URL into your browser:

{{ $trackUrl }}

Please keep this link private — anyone with it can view {{ ($documentOnly ?? false) ? 'this agreement' : 'your application' }}.

Ngā mihi,<br>
The EP team
</x-mail::message>
