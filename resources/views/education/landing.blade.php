<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="description" content="Meet the ePathways Education team at your school. Register your interest and explore your study journey in New Zealand.">
<meta name="color-scheme" content="light dark"><title>Your New Zealand chapter · EP Education</title>
<link rel="icon" type="image/png" sizes="48x48" href="/favicon.png?v={{ filemtime(public_path('favicon.png')) }}"><link rel="stylesheet" href="/education-landing/landing.css?v={{ filemtime(public_path('education-landing/landing.css')) }}">
<link rel="stylesheet" href="/education-landing/registration-simple.css?v={{ filemtime(public_path('education-landing/registration-simple.css')) }}"><script src="/education-landing/registration-simple.js?v={{ filemtime(public_path('education-landing/registration-simple.js')) }}" defer></script>
</head><body>
@include('education.landing-form')
<section id="ep-public-contact" class="ep-public-screen" hidden>
 <button type="button" data-return>← Back to registration</button>
 <span class="ep-kicker">A PERSON TO GUIDE YOU</span><h2>Talk to your Education adviser.</h2><p class="ep-sub">Ask us about your school visit, event registration or study options in New Zealand.</p>
 <div class="ep-action-grid">
 @if($contact['messenger'])
 <article class="ep-action-card"><span class="ep-kicker">MESSAGE THE TEAM</span><h3>Ask a quick question</h3><p>Continue the conversation with the ePathways team on Messenger. Share the event and school you joined so we can connect you with the right adviser.</p><a href="{{ $contact['messenger'] }}" target="_blank" rel="noopener noreferrer" class="ep-link-button ep-primary">Chat with the team ↗</a></article>
 @endif
 <article class="ep-action-card"><span class="ep-kicker">EMAIL THE TEAM</span><h3>Prefer email?</h3><p>Reply to your welcome email or contact our monitored inbox. Include your school and event name so the Education team can follow up.</p>@if($contact['email_url'])<a href="{{ $contact['email_url'] }}" class="ep-link-button">Email an adviser →</a>@endif </article>
 </div>
 @if($contact['phone'])<p class="ep-contact-phone">Prefer to call? <a href="tel:{{ preg_replace('/[^+0-9]/', '', $contact['phone']) }}">{{ $contact['phone'] }}</a></p>@endif
</section>
<section id="ep-public-next" class="ep-public-screen" hidden>
 <button type="button" data-return>← Back to registration</button>
 <span class="ep-kicker">ONE STEP AT A TIME</span><h2>Your next action.</h2><p class="ep-sub">Prepare a few questions for your Education adviser.</p>
 <div class="ep-action-grid"><article class="ep-action-card"><span class="ep-kicker">DO THIS NEXT</span><h3>Share your questions</h3><p>Tell us about the courses you’re considering, when you would like to start, or anything you want help with.</p><label>Your questions<textarea id="ep-questions" rows="4" maxlength="3000" placeholder="For example: I’d like to compare IT courses and understand the next intake."></textarea></label><button type="button" class="ep-primary" id="ep-save-questions">Send to my adviser →</button><p id="ep-prepare-status" role="status" hidden></p></article><article class="ep-action-card"><span class="ep-kicker">STAY CONNECTED</span><h3>Your applicant profile</h3><p>Already have an activated account? Log in to review your profile and progress. New applicants receive an invitation after staff approval.</p><a class="ep-link-button ep-primary" href="{{ url('/login') }}">Log in to my profile →</a><button type="button" data-screen-open="contact">Contact my adviser</button></article></div>
</section>
<footer class="ep-public-footer"><span>EP Education · Your New Zealand chapter</span></footer>
</div>
<noscript><p style="padding:24px;text-align:center">Please enable JavaScript to complete this registration, or <a href="{{ $contact['email_url'] ?: url('/') }}">contact the Education team</a>.</p></noscript>
</body></html>
