@php
$editingAnswers = old('_form') === 'answers-'.$r->id;
$answerValue = fn($field) => $editingAnswers ? old($field) : $r->{$field};
@endphp
<tr class="rsvp-answer-row"><td colspan="5">
<details class="editor-disclosure rsvp-answer-editor" @if($editingAnswers) open @endif>
    <summary><span><strong>Edit answers <span class="sr-only">for {{ $r->full_name }}</span></strong></span><svg class="chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></summary>
    <div class="disclosure-body">
        <form method="post" action="{{ route('education.rsvps.answers', $r) }}#rsvp-{{ $r->id }}" data-save>
            @csrf @method('PUT')
            <input type="hidden" name="_form" value="answers-{{ $r->id }}">
            <input type="hidden" name="revision" value="{{ $editingAnswers ? old('revision') : $r->answersRevision() }}">
            @if($editingAnswers && $errors->any())<div class="notice notice-error" role="alert">{{ $errors->first() }}</div>@endif
            <p class="supporting">Correct this attendee’s RSVP details and recorded email choices. Changes are logged. The selected event stays {{ $r->visit->school }} · {{ $r->visit->activity }}.</p>
            <div class="field-grid">
                <label>Full name<input name="full_name" required maxlength="150" value="{{ $answerValue('full_name') }}"></label>
                <label>Email address<input name="email" type="email" required maxlength="254" value="{{ $answerValue('email') }}"></label>
                <label>Contact number<input name="phone" type="tel" maxlength="40" value="{{ $answerValue('phone') }}"></label>
                @if($r->visit->kind === 'partnership' || $r->role)<label>Role / organisation<input name="role" maxlength="100" value="{{ $answerValue('role') }}"></label>@else<input type="hidden" name="role" value="">@endif
            </div>
            <fieldset class="rsvp-answer-choices"><legend>Recorded email choices</legend>
                @foreach(['email_reminders'=>'Event reminders','future_invites'=>'Future event invitations','email_follow_up'=>'Study tips and adviser follow-ups','email_newsletter'=>'Luvep Education newsletter'] as $choice=>$choiceLabel)
                    <input type="hidden" name="{{ $choice }}" value="0">
                    <label><input type="checkbox" name="{{ $choice }}" value="1" @checked($answerValue($choice))><span>{{ $choiceLabel }}</span></label>
                @endforeach
            </fieldset>
            <p class="supporting">Record the attendee’s choices accurately. Follow-up emails do not require email verification. Changing the email or name identity clears its old confirmation and applicant match. Saving does not send an email or change a programme application.</p>
            <div class="form-actions"><button type="submit">Save RSVP answers</button></div>
        </form>
        @if(!$r->verified_at && $r->attendance_status !== 'cancelled' && $r->visit->status !== 'cancelled')
        <form method="post" action="{{ route('education.rsvps.confirmation', $r) }}#rsvp-{{ $r->id }}" class="form-actions" data-save>
            @csrf
            <input type="hidden" name="revision" value="{{ $r->answersRevision() }}">
            <button type="submit" class="secondary">Send verification email</button>
            <span>Sends to {{ $r->email }} for reminders, newsletters and applicant matching. Save corrections first. Follow-ups do not need this step.</span>
        </form>
        @endif
    </div>
</details>
</td></tr>
