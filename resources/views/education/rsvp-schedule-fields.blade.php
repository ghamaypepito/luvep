@php
 $defaults = $visit ? $visit->only(['school','activity','city','mode','timezone','location','schedule_confirmed','status','is_hidden']) : ['school'=>'','activity'=>'Education event','city'=>'','mode'=>'in_person','timezone'=>'Asia/Manila','location'=>'','schedule_confirmed'=>0,'status'=>'open','is_hidden'=>0];
 $defaults['event_date']=$visit?->event_date?->format('Y-m-d'); $defaults['event_time']=$visit?->event_time;
 $value=fn($key)=>old('_form')===$formId ? old($key,$defaults[$key]??'') : ($defaults[$key]??'');
@endphp
<input type="hidden" name="_form" value="{{ $formId }}">
<div class="field-grid">
 <label>School / university<input name="school" value="{{ $value('school') }}" list="known-schools" required maxlength="180" placeholder="Choose an existing school or type a new one"></label>
 <label>Event title<input name="activity" value="{{ $value('activity') }}" required maxlength="180"></label>
 <label>City / campus<input name="city" value="{{ $value('city') }}" maxlength="120"></label>
 <label>Event format<select name="mode"><option value="in_person" @selected($value('mode')==='in_person')>In person</option><option value="online" @selected($value('mode')==='online')>Online</option></select></label>
 <label>Event date<input type="date" name="event_date" value="{{ $value('event_date') }}"></label>
 <label>Event time<input type="time" name="event_time" value="{{ $value('event_time') }}"></label>
 <label>Time zone<select name="timezone"><option value="Asia/Manila" @selected($value('timezone')==='Asia/Manila')>Philippine time</option><option value="Pacific/Auckland" @selected($value('timezone')==='Pacific/Auckland')>New Zealand time</option></select></label>
 <label>Room / joining details<input name="location" value="{{ $value('location') }}" maxlength="255"></label>
 <label>Publish date & time<select name="schedule_confirmed"><option value="0" @selected(!$value('schedule_confirmed'))>To be confirmed — hide date and time</option><option value="1" @selected($value('schedule_confirmed'))>Confirmed — publish date and time</option></select></label>
 <label>Event visibility<select name="is_hidden"><option value="0" @selected(!$value('is_hidden'))>Shown on public pages</option><option value="1" @selected($value('is_hidden'))>Hidden from public pages</option></select></label>
 <label>RSVP availability<select name="status"><option value="open" @selected($value('status')==='open')>Open for RSVPs</option><option value="closed" @selected($value('status')==='closed')>Closed for new RSVPs</option>@if($visit)<option value="cancelled" @selected($value('status')==='cancelled')>Event cancelled</option>@endif</select></label>
</div>

<p class="supporting">Hidden events are removed from public RSVP and programme-registration options. Existing attendance, applicant history and updates for current attendees are retained. Each schedule has its own visibility setting.</p>
