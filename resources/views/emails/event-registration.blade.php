<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $eventName }}</title>
</head>
<body style="margin:0; padding:0; background-color:#eef0f4; -webkit-text-size-adjust:100%; -ms-text-size-adjust:100%;">
    {{-- Preheader (hidden preview text) --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; font-size:1px; line-height:1px; color:#eef0f4;">
        You're registered for {{ $eventName }}. Here's what happens next.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#eef0f4;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px; max-width:600px; background-color:#ffffff; border-radius:6px; overflow:hidden; font-family:'Segoe UI', Arial, Helvetica, sans-serif;">

                    {{-- Header banner (hosted URL — no attachment) --}}
                    <tr>
                        <td style="padding:0; background-color:#0f2f1c;">
                            <img src="{{ $siteUrl }}/images/email/{{ $headerImage }}" alt="EP" width="600" style="display:block; width:100%; max-width:600px; height:auto; border:0;">
                        </td>
                    </tr>

                    {{-- Greeting --}}
                    <tr>
                        <td style="padding:28px 40px 0 40px;">
                            <p style="margin:0; font-size:16px; font-weight:700; color:#2e7d32;">
                                Kia Ora / Mabuhay, {{ $firstName }}!
                            </p>
                        </td>
                    </tr>

                    {{-- Confirmation line --}}
                    <tr>
                        <td style="padding:16px 40px 0 40px;">
                            <p style="margin:0; font-size:14px; line-height:1.6; color:#333333;">
                                Thank you for registering for <strong style="color:#111111;">{{ $eventName }}</strong>.
                                Your spot is confirmed — we're looking forward to seeing you there.
                            </p>
                        </td>
                    </tr>

                    {{-- Event details card --}}
                    @if ($dateLine || $timeLine || $locationLine)
                    <tr>
                        <td style="padding:20px 40px 0 40px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f7f5; border:1px solid #e2ebe4; border-radius:6px;">
                                <tr>
                                    <td style="padding:18px 22px;">
                                        <p style="margin:0 0 10px 0; font-size:10px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; color:#2e7d32;">
                                            Your registration
                                        </p>
                                        <p style="margin:0; font-size:15px; font-weight:700; color:#111111;">{{ $eventName }}</p>
                                        @if ($dateLine)
                                            <p style="margin:8px 0 0 0; font-size:13px; color:#444444;"><strong>Date:</strong> {{ $dateLine }}</p>
                                        @endif
                                        @if ($timeLine)
                                            <p style="margin:4px 0 0 0; font-size:13px; color:#444444;"><strong>Time:</strong> {{ $timeLine }}</p>
                                        @endif
                                        @if ($locationLine)
                                            <p style="margin:4px 0 0 0; font-size:13px; color:#444444;"><strong>Location:</strong> {{ $locationLine }}</p>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endif

                    {{-- Body copy --}}
                    <tr>
                        <td style="padding:20px 40px 0 40px;">
                            <p style="margin:0 0 14px 0; font-size:14px; line-height:1.6; color:#333333;">
                                Thank you for your interest in exploring opportunities to study and work in New Zealand
                                with EP.
                            </p>
                            <p style="margin:0 0 14px 0; font-size:14px; line-height:1.6; color:#333333;">
                                We are an <strong>education consultancy</strong> that helps students and professionals
                                choose the right study pathway, understand requirements, and complete their application
                                process step by step. Our goal is to make your journey clear, guided, and achievable —
                                from selecting the right program to preparing your requirements.
                            </p>
                            <p style="margin:0 0 14px 0; font-size:14px; line-height:1.6; color:#333333;">
                                To better assist you, I, <strong>{{ $eventContact['first_name'] ?? 'Bryll' }}</strong> of EP, will personally
                                reach out within the <strong>next 24 hours</strong> to discuss your background and possible
                                options based on your profile.
                            </p>
                            <p style="margin:0 0 14px 0; font-size:14px; line-height:1.6; color:#333333;">
                                This will be a quick <strong>10–15 minute session</strong>, where we can assess your profile
                                and discuss your possible pathway.
                            </p>
                            <p style="margin:0; font-size:14px; line-height:1.6; color:#333333;">
                                Looking forward to speaking with you and helping you take the next step.
                            </p>
                        </td>
                    </tr>

                    @if ($eventContact)
                    <tr>
                        <td style="padding:16px 40px 0 40px;">
                            <p style="margin:0; font-size:14px; line-height:1.6; color:#333333;">
                                Kind regards,<br>
                                <strong>{{ $eventContact['name'] }}</strong><br>
                                <a href="mailto:{{ $eventContact['email'] }}" style="color:#2e7d32;">{{ $eventContact['email'] }}</a><br>
                                <a href="tel:{{ preg_replace('/[^+\d]/', '', $eventContact['phone']) }}" style="color:#2e7d32;">{{ $eventContact['phone'] }}</a>
                            </p>
                        </td>
                    </tr>
                    @endif

                    {{-- Footer CTA — consultation image with the CTA buttons
                         baked into it (Gmail can't overlay HTML on an image);
                         the whole image links to the booking page. --}}
                    @php
                        $footerUrl = $footerImage ? $siteUrl.'/images/email/'.$footerImage : app(\App\Services\EmailFooterComposer::class)
                            ->composeUrl(public_path('images/coffee-cta.png'), 'BOOK NOW', $phone ? 'CALL '.$phone : null);
                    @endphp
                    @if ($footerUrl)
                    <tr>
                        <td style="padding:26px 0 0 0;">
                            <a href="{{ $bookUrl }}" target="_blank">
                                <img src="{{ $footerUrl }}" alt="Book your free consultation with EP" width="600" style="display:block; width:100%; max-width:600px; height:auto; border:0;">
                            </a>
                        </td>
                    </tr>
                    @endif

                    {{-- Social icons --}}
                    <tr>
                        <td style="padding:22px 40px 6px 40px;" align="center">
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    @if ($facebook)
                                    <td style="padding:0 5px;">
                                        <a href="{{ $facebook }}" target="_blank"><img src="{{ $siteUrl }}/images/email/social/facebook.png" alt="Facebook" width="30" height="30" style="display:block; border:0;"></a>
                                    </td>
                                    @endif
                                    @if ($messenger)
                                    <td style="padding:0 5px;">
                                        <a href="{{ $messenger }}" target="_blank"><img src="{{ $siteUrl }}/images/email/social/messenger.png" alt="Messenger" width="30" height="30" style="display:block; border:0;"></a>
                                    </td>
                                    @endif
                                    <td style="padding:0 5px;">
                                        <a href="{{ $siteUrl }}" target="_blank"><img src="{{ $siteUrl }}/images/email/social/website.png" alt="Website" width="30" height="30" style="display:block; border:0;"></a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:14px 40px 30px 40px;" align="center">
                            <p style="margin:0; font-size:11px; color:#aaaaaa;">
                                Copyright &copy; {{ date('Y') }} EP. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
