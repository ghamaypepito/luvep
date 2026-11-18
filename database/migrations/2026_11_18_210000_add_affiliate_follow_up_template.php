<?php

use App\Models\MessageTemplate;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        MessageTemplate::withTrashed()->firstOrCreate(
            ['key' => 'affiliate_client_follow_up', 'department' => 'agent'],
            [
                'name' => 'Affiliate client follow-up',
                'description' => 'Sample follow-up for an affiliate partner to personalise before sending.',
                'channels' => ['email'],
                'email_subject' => 'Checking in, {{first_name}}',
                'email_body' => '<p>Kia ora {{first_name}},</p><p>I am checking in on your enquiry with EP. Please let me know if your plans have changed or if you would like help with your next step.</p><p>If you have a question for the EP team, reply to this email and I will help connect you with the right person.</p><p>Kind regards,<br>Your EP Affiliate Partner</p>',
                'variables_documented' => ['first_name'],
                'is_active' => true,
            ],
        );
    }

    public function down(): void
    {
        // Keep the template: staff may have personalised it after installation.
    }
};
