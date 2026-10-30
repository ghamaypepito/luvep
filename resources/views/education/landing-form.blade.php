<div id="ep-education" data-theme="system">
 <header class="ep-head">
  <div class="ep-brand"><img class="ep-brand-mark" src="/education-landing/ep-logo.png" alt="EP logo"><span>Education</span></div>
  <nav class="ep-header-actions" aria-label="Education navigation">
   <a class="ep-site-link" href="{{ url('/') }}"><span>Visit website</span><svg class="ep-external-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></a>
   <button type="button" data-screen-open="contact">Contact an adviser</button>
   <a class="ep-link-button ep-header-login" href="{{ url('/login') }}"><span>Applicant login</span><svg class="ep-external-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 17 17 7M7 7h10v10"/></svg></a>
  </nav>
 </header>
 <p class="ep-intro">Your New Zealand chapter starts here.</p>
 <section id="ep-landing">
  <div class="ep-layout">
   <aside class="ep-story"><img class="ep-study-photo" src="/education-landing/students.jpg" alt="Three students studying together outdoors"><span class="ep-kicker">YOUR NEW ZEALAND CHAPTER</span><div class="ep-story-copy"><span class="ep-kicker">STUDY. GROW. BEGIN AGAIN.</span><h1>A big future.<br>A little<br><em>first step.</em></h1><p>Meet our team at your school and explore your next chapter in New Zealand.</p></div><div class="ep-event"><i data-lucide="graduation-cap" aria-hidden="true"></i><strong>Your New Zealand journey</strong><span>School visits, events and next steps</span><span>One place to begin</span></div></aside>
   <div class="ep-journey">
    <form id="ep-form" action="{{ url('/education/register') }}" method="post" novalidate>
     @csrf
     <input type="hidden" name="form_version" value="simple">
     <input type="hidden" id="ep-submission-id" name="submission_id" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
     <div class="ep-honeypot" aria-hidden="true"><label>Leave blank<input name="website" type="text" tabindex="-1" autocomplete="off"></label></div>
     <p id="ep-error" role="alert" hidden></p>
     <section id="ep-registration-content">
      <h2>Let’s stay in touch.</h2>
      <p class="ep-sub">Leave your details and select the Education event you joined.</p>
      <figure class="ep-team-figure"><img class="ep-team-photo" src="/education-landing/education-team-ep.webp" alt="EP Education team banner" loading="lazy" decoding="async" width="2170" height="725"><figcaption>Your Education team, here to guide your next step.</figcaption></figure>
      <fieldset id="ep-registration-fields" class="ep-simple-fields">
       <p class="ep-fine" id="ep-returning-rsvp" role="status" hidden></p>
       <label>Full name<input id="ep-full-name" name="full_name" autocomplete="name" required maxlength="150" placeholder="Your full name"></label>
       <label>Email address<input id="ep-email" name="email" type="email" autocomplete="email" required maxlength="255" placeholder="you@example.com"></label>
       <label>Contact number<input id="ep-phone" name="phone" type="tel" autocomplete="tel" required minlength="7" maxlength="40" placeholder="e.g. +63 9XX XXX XXXX"></label>
       <label class="ep-same-number"><input id="ep-whatsapp-same" name="whatsapp_same_as_phone" type="checkbox" checked> My WhatsApp number is the same as my contact number</label>
       <label id="ep-whatsapp-label" hidden>WhatsApp number<input id="ep-whatsapp" name="whatsapp" type="tel" autocomplete="tel" minlength="7" maxlength="40" placeholder="Include your country code" disabled></label>
       <label>Country you are from<select id="ep-country" name="country" required>@foreach($countries as $country)<option value="{{ $country }}" @selected($country==='Philippines')>{{ $country }}</option>@endforeach<option value="__other__">Other country</option></select></label>
       <label id="ep-other-country-label" hidden>Country name<input id="ep-other-country" name="other_country" maxlength="100" disabled></label>
       <label>Which event did you join?<select id="ep-visit" name="visit_id" required><option value="">Choose your event</option>@foreach($registrationVisits as $visit)<option value="{{ $visit['id'] }}">{{ $visit['school'] }} · {{ $visit['activity'] }} · {{ $visit['summary'] }}</option>@endforeach<option value="__other__">Other event</option></select></label>
       <label id="ep-other-event-label" hidden>Event name<input id="ep-event-name" name="event_name" maxlength="180" placeholder="Enter the event you joined" disabled></label>
       <p class="ep-managed-summary" id="ep-managed-summary" role="status" hidden></p>
       <label class="ep-consent"><input id="ep-consent" name="consent" type="checkbox" required> I agree to be contacted about this event and my education enquiry.</label>
      </fieldset>
     </section>
     <section id="ep-success" hidden aria-live="polite"><i data-lucide="circle-check" aria-hidden="true"></i><span class="ep-kicker">YOU’VE TAKEN THE FIRST STEP</span><h2>You’re registered,<br><span id="ep-greeting">Maria</span>.</h2><p>We’ve received your details. Your Education team will confirm the event information and help with your next steps.</p><div class="ep-receipt"><strong id="ep-receipt-session"></strong><span>Next: your Education adviser reviews your enquiry and follows up.</span></div><div class="ep-button-row"><button type="button" class="ep-primary" data-screen-open="next">Continue to my next step →</button><button type="button" data-screen-open="contact">Contact my adviser</button><a class="ep-link-button" href="{{ url('/login') }}">Log in to my profile</a></div></section>
     <footer class="ep-actions"><span id="ep-helper">Your details go directly to the Education team.</span><button type="submit" id="ep-next" class="ep-primary">Register →</button></footer>
    </form>
   </div>
  </div>
 </section>

<script type="application/json" id="education-registration-visits">@json($registrationVisits)</script>
