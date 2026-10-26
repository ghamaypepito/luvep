<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Welcome to your New Zealand chapter</title></head>
<body style="margin:0;padding:24px 12px;background:#f5f7f5;color:#202824;font-family:Arial,sans-serif;line-height:1.6">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="600" cellspacing="0" cellpadding="0" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #dfe5e1;border-radius:14px"><tr><td style="padding:28px">
<img src="{{ url('/education-landing/ep-logo.png') }}" alt="EP — ePathways" width="72" style="display:block;height:auto;margin-bottom:24px">
<img src="{{ url('/education-landing/student-welcome.jpg') }}" alt="Students welcoming a friend on campus" width="544" style="display:block;width:100%;height:auto;border-radius:10px;margin-bottom:28px">
<h1 style="font-size:25px;line-height:1.3;margin:0 0 18px">Welcome, {{ $registration->contact_details['first_name'] }}.</h1>
<p style="margin:0 0 18px">Thank you for sharing your education enquiry. Your next chapter in New Zealand starts with a little first step.</p>
<p style="margin:0 0 18px">We’ve received the details of the event you joined:</p>
<p style="margin:0 0 24px;padding:18px;background:#edf7f0;border-radius:8px"><strong>{{ $registration->eventSummary() }}</strong><br>{{ $registration->school }}<br>Country you are from: {{ $registration->country }}</p>
<p style="margin:0 0 24px">Your Education adviser will review your enquiry, confirm the event information, and contact you about your next steps. You don’t need to upload documents to get started.</p>
<table role="presentation" cellspacing="0" cellpadding="0"><tr><td bgcolor="#267443" style="border-radius:8px"><a href="{{ url('/login') }}" style="display:inline-block;padding:14px 20px;color:#ffffff;font-weight:bold;text-decoration:none">Log in / Applicant profile →</a></td></tr></table>
<p style="margin:24px 0"><a href="{{ url('/') }}" style="color:#267443">Visit website</a> &nbsp; · &nbsp; <a href="{{ $contact['email_url'] ?: url('/education/register#contact') }}" style="color:#267443">Contact adviser</a></p>
<p style="margin:0 0 18px;font-size:13px;color:#64706a">New applicant? Your adviser requests portal access, an admin approves it, and you receive an invitation to set up your account. Registering for an event does not activate an account automatically.</p>
<p style="margin:0;font-size:13px;color:#64706a">This email acknowledges your enquiry; event details remain subject to confirmation. You can reply to this email to contact the team.</p>
</td></tr></table></td></tr></table></body></html>
