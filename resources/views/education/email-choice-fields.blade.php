<fieldset class="education-email-choices">
    <legend>Education emails (optional)</legend>
    <label class="{{ $labelClass }}">
        <input type="checkbox" name="email_follow_up" value="1">
        <span>Email me study tips and adviser follow-ups after my event.</span>
    </label>
    <label class="{{ $labelClass }}">
        <input type="checkbox" name="email_newsletter" value="1">
        <span>Send me the Luvep Education newsletter.</span>
    </label>
    <p>{{ $help }} You can unsubscribe at any time.</p>
</fieldset>
