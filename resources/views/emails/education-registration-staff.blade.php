<!doctype html><html lang="en"><head><meta charset="utf-8"><title>New Education registration</title></head><body style="font-family:Arial,sans-serif;line-height:1.6;color:#202824">
<img src="{{ url('/education-landing/ep-logo.png') }}" width="72" alt="EP logo" style="height:auto">
<h1 style="font-size:24px">New Education registration</h1>
<p>{{ $registration->contact_details['full_name'] ?? trim($registration->contact_details['first_name'].' '.$registration->contact_details['last_name']) }} submitted an Education event enquiry.</p>
<p><strong>Event:</strong> {{ $registration->eventSummary() }}
@if($registration->school)<br><strong>School / business / venue:</strong> {{ $registration->school }}@endif
<br><strong>Country of origin:</strong> {{ $registration->country }}
<br><strong>Email:</strong> {{ $registration->contact_details['email'] }}
<br><strong>Contact number:</strong> {{ $registration->contact_details['phone'] }}
@if(!empty($registration->contact_details['whatsapp']))<br><strong>WhatsApp:</strong> {{ $registration->contact_details['whatsapp'] }}@endif
@if($registration->goal)<br><strong>Goal:</strong> {{ $registration->goal }}@endif
@if($registration->planning_timeline)<br><strong>Study timeline:</strong> {{ $registration->planning_timeline }}@endif
@if($registration->education_level)<br><strong>Education level:</strong> {{ $registration->education_level }}@endif</p>
<p>Review the event details and contact the applicant about their next steps. Existing ownership and pipeline progress have been preserved.</p>
<p><a href="{{ url('/portal/education/leads/'.$registration->lead_id) }}" style="color:#267443">Open the Education lead →</a></p>
</body></html>
