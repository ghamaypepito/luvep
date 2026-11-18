<x-mail::message>
Hello {{ $lead->first_name }},

Please sign in to your client portal to complete your visa assessment. Your answers will be saved to your existing case.

<x-mail::button :url="$assessmentUrl">
Complete assessment
</x-mail::button>

You can save your progress and return to the same assessment in your portal.

Thank you,<br>
{{ config('app.name') }}
</x-mail::message>
