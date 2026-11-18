<!doctype html><html lang="en"><head><meta charset="utf-8"><title>New Education registration</title></head><body style="font-family:Arial,sans-serif;line-height:1.6;color:#202824">
<img src="{{ url('/education-landing/ep-logo.png') }}" width="72" alt="EP logo" style="height:auto">
<h1 style="font-size:24px">New Education registration</h1>
<p>{{ $registration->contact_details['first_name'] }} {{ $registration->contact_details['last_name'] }} submitted a school-event enquiry.</p>
<p><strong>Event:</strong> {{ $registration->eventSummary() }}<br><strong>School / university:</strong> {{ $registration->school }}<br><strong>Country of origin:</strong> {{ $registration->country }}<br><strong>Goal:</strong> {{ $registration->goal }}<br><strong>Study timeline:</strong> {{ $registration->planning_timeline }}<br><strong>Education level:</strong> {{ $registration->education_level }}</p>
<p>Confirm the typed event and school visit, then review the applicant and follow up. Existing ownership and pipeline progress have been preserved.</p>
<p><a href="{{ url('/portal/education/leads/'.$registration->lead_id) }}" style="color:#267443">Open the Education lead →</a></p>
</body></html>
