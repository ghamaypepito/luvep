<?php

namespace Database\Seeders;

use App\Models\MessageTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds (or updates) the shared 'event_registration' message template so the
 * event confirmation email is identical on every environment. DB records and
 * uploaded images don't travel with a code deploy — this keeps the template's
 * subject/body/sender in sync. Custom banner/footer images (if any) are
 * uploaded per-environment via the template editor and are left untouched.
 */
class EventRegistrationTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $body = <<<'HTML'
<p style="color:#2e7d32;font-weight:700;">Kia Ora / Mabuhay, {{first_name}}!</p>
<p>Thank you for registering for <strong>{{event_name}}</strong>. Your spot is confirmed &mdash; we're looking forward to seeing you there.</p>
<p style="text-align:center;"><strong>Date:</strong><br>{{event_date}}</p>
<p style="text-align:center;"><strong>Time:</strong><br>{{event_time}}</p>
<p style="text-align:center;"><strong>Location:</strong><br>{{event_location}}</p>
<p>Thank you for your interest in exploring opportunities to study and work in New Zealand with ePathways.</p>
<p>We are an <strong>education consultancy</strong> that helps students and professionals choose the right study pathway, understand requirements, and complete their application process step by step. Our goal is to make your journey clear, guided, and achievable &mdash; from selecting the right program to preparing your requirements.</p>
<p>To better assist you, I, <strong>Bryll</strong> of ePathways Philippines, will personally reach out within the <strong>next 24 hours</strong> to discuss your background and possible options based on your profile.</p>
<p>This will be a quick <strong>10&ndash;15 minute session</strong>, where we can assess your profile and discuss your possible pathway.</p>
<p>Looking forward to speaking with you and helping you take the next step.</p>
HTML;

        MessageTemplate::updateOrCreate(
            ['key' => 'event_registration', 'department' => ''],
            [
                'name' => 'Event Registration',
                'description' => 'Confirmation email sent automatically when someone registers for an event.',
                'channels' => ['email'],
                'email_subject' => "You're registered — {{event_name}}",
                'email_body' => $body,
                'from_email' => 'hello@epathways.ph',
                'from_name' => 'Eireen Serrano - EP',
                'is_active' => true,
            ]
        );
    }
}
