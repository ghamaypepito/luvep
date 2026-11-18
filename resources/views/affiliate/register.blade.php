<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Register your enquiry with EP through your Affiliate Partner.">
    <title>{{ $complete ? 'Thank you for registering' : 'Your next chapter' }} · EP</title>
    <link rel="icon" href="/images/ep-logo.png">
    <link rel="stylesheet" href="/affiliate/registration.css?v={{ filemtime(public_path('affiliate/registration.css')) }}">
</head>
<body>
<main class="registration">
    <header class="brand"><a href="{{ url('/') }}" aria-label="EP home"><img src="/images/ep-logo.png" alt="EP"></a><span>emerge. energise. evolve.</span></header>
    @if (! $partner)
        <section class="confirmation"><h1>This referral link is unavailable.</h1><p>Please ask your Affiliate Partner for their current registration link.</p><a class="button" href="{{ url('/') }}">Visit EP</a></section>
    @elseif ($complete)
        <section class="confirmation">
            <div class="check" aria-hidden="true">✓</div>
            <h1>Thank you for registering.</h1>
            <p>Your enquiry has been received by EP. Our team will follow up with you about your next step.</p>
            <p>Referred by <strong>{{ $partner->name }}</strong></p>
            <a class="button" href="{{ url('/') }}">Explore EP</a>
        </section>
    @else
        <div class="registration-shell">
            <aside class="story"><img src="/education-landing/students.jpg" alt="Students exploring their study options"><div><h1>Your next chapter starts here.</h1><p>Tell us a little about yourself. Our team will help you explore your options in New Zealand.</p></div></aside>
            <section class="form-panel" aria-labelledby="form-heading">
                <span class="affiliate">Referred by {{ $partner->name }}</span>
                <h2 id="form-heading">Let’s get to know you.</h2><p class="intro">A few details to help us follow up.</p>
                @if ($errors->any())
                    <div class="errors" role="alert"><p>Please check the highlighted details.</p><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
                @endif
                <form action="{{ route('affiliate.registration.store', ['code' => $partner->referral_code]) }}" method="post">
                    @csrf
                    <div class="fields">
                        <label>First name<input name="first_name" autocomplete="given-name" maxlength="80" value="{{ old('first_name') }}" required @error('first_name') aria-invalid="true" @enderror></label>
                        <label>Last name<input name="last_name" autocomplete="family-name" maxlength="80" value="{{ old('last_name') }}" required @error('last_name') aria-invalid="true" @enderror></label>
                        <label class="wide">Email address<input type="email" name="email" autocomplete="email" maxlength="150" value="{{ old('email') }}" required @error('email') aria-invalid="true" @enderror></label>
                        <label>Phone number<input type="tel" name="phone" autocomplete="tel" maxlength="35" value="{{ old('phone') }}" required @error('phone') aria-invalid="true" @enderror></label>
                        <label>Country you live in<input name="country" autocomplete="country-name" maxlength="80" value="{{ old('country') }}" required @error('country') aria-invalid="true" @enderror></label>
                        <label class="wide">What can we help you with?<select name="service" aria-label="What can we help you with?" required @error('service') aria-invalid="true" @enderror><option value="">Choose a service</option>@foreach ($services as $service)<option @selected(old('service') === $service)>{{ $service }}</option>@endforeach</select></label>
                    </div>
                    <label class="consent"><input type="checkbox" name="consent" value="1" required @error('consent') aria-invalid="true" @enderror><span>I agree to be contacted by EP and {{ $partner->name }} about my enquiry.</span></label>
                    <button class="button" type="submit">Submit registration</button>
                    <p class="privacy">We’ll use these details to respond to your enquiry.</p>
                </form>
            </section>
        </div>
    @endif
</main>
</body>
</html>
