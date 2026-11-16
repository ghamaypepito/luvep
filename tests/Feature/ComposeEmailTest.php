<?php

namespace Tests\Feature;

use App\Mail\TemplatedMessage;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ComposeEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_education_compose_offers_active_email_templates_from_the_shared_library(): void
    {
        $welcome = MessageTemplate::create([
            'name' => 'Education Welcome', 'department' => 'sales', 'channels' => ['email'],
            'email_subject' => 'Welcome, {{first_name}}', 'email_body' => '<p>Welcome to Luvep</p>',
            'is_active' => true,
        ]);
        $draft = MessageTemplate::create([
            'name' => 'Welcome Draft', 'department' => 'education', 'channels' => ['email'],
            'email_body' => '<p>Draft</p>', 'is_active' => false,
        ]);
        $sms = MessageTemplate::create([
            'name' => 'Welcome SMS', 'department' => 'education', 'channels' => ['sms'],
            'sms_body' => 'Welcome', 'is_active' => true,
        ]);
        $legacy = MessageTemplate::create([
            'name' => 'Tracker welcome', 'department' => 'education', 'channels' => ['email'],
            'email_body' => '[Open your tracker]({{tracker_url}})', 'is_active' => true,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->get('/portal/education/compose')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portal/education/Compose')
                ->where('templates', function ($templates) use ($welcome, $draft, $sms, $legacy) {
                    $byId = collect($templates)->keyBy('id');

                    return $byId->has($welcome->id)
                        && $byId[$welcome->id]['email_subject'] === 'Welcome, {{first_name}}'
                        && $byId[$welcome->id]['email_body'] === '<p>Welcome to Luvep</p>'
                        && str_contains($byId[$legacy->id]['email_body'] ?? '', '<a href="{{tracker_url}}">Open your tracker</a>')
                        && ! $byId->has($draft->id)
                        && ! $byId->has($sms->id);
                }));
    }

    public function test_compose_sends_to_a_lead_and_a_typed_address_and_logs_each(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => 'admin']);
        $lead = Lead::create(['first_name' => 'Angi', 'last_name' => 'L', 'email' => 'angi@example.com']);

        $this->actingAs($admin)->post('/admin/email/compose/send', [
            'lead_ids' => [$lead->id],
            'emails' => ['someone@external.com'],
            'subject' => 'Hi {{first_name}}',
            'body' => '<p>Hello {{first_name}}</p>',
        ])->assertRedirect();

        // One log per recipient, tagged source=compose.
        $this->assertSame(2, MessageLog::where('source', 'compose')->count());
        $this->assertSame(1, MessageLog::where('source', 'compose')->where('recipient_type', 'lead')->where('recipient_id', $lead->id)->count());
        $this->assertSame(1, MessageLog::where('source', 'compose')->where('recipient_type', 'raw')->where('recipient_address', 'someone@external.com')->count());

        Mail::assertQueued(TemplatedMessage::class, fn (TemplatedMessage $m) => $m->hasTo('angi@example.com') && str_contains($m->markdownBody, 'Angi'));
        Mail::assertQueued(TemplatedMessage::class, fn (TemplatedMessage $m) => $m->hasTo('someone@external.com') && ! str_contains($m->markdownBody, '{{first_name}}'));
    }

    public function test_compose_requires_at_least_one_recipient(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/admin/email/compose/send', [
            'subject' => 'Hi', 'body' => '<p>x</p>',
        ])->assertSessionHasErrors('error');
    }
}
