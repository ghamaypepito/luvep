<?php

namespace Tests\Feature;

use App\Mail\TemplatedMessage;
use App\Models\Lead;
use App\Models\MessageLog;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccommodationComposeTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/portal/accommodation/compose';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_picker_searches_only_accommodation_contacts_and_shows_their_property(): void
    {
        $property = $this->property();
        $tenant = Tenant::create([
            'property_id' => $property->id, 'first_name' => 'Sample', 'family_name' => 'Tenant', 'email' => 'tenant@example.test',
        ]);
        Tenant::create(['property_id' => $property->id, 'first_name' => 'Missing', 'family_name' => 'Email']);
        Lead::create(['first_name' => 'Sample', 'last_name' => 'Other Lead', 'email' => 'lead@example.test']);
        $this->actingAs(User::factory()->create(['role' => 'accommodation']));

        foreach ([
            ['tenant', 'sample tenant', 'tenant:'.$tenant->id, 'tenant@example.test'],
            ['manager', 'manager@example.test', 'manager:'.$property->id, 'manager@example.test'],
            ['owner', 'SAMPLE OWNER', 'owner:'.$property->id, 'owner@example.test'],
        ] as [$type, $query, $id, $email]) {
            $this->getJson(self::PATH.'/contacts?'.http_build_query(['type' => $type, 'q' => $query]))
                ->assertOk()->assertJsonCount(1, 'contacts')
                ->assertJsonPath('contacts.0.id', $id)->assertJsonPath('contacts.0.email', $email)
                ->assertJsonPath('contacts.0.property', '10 Example Road');
        }
        $this->getJson(self::PATH.'/contacts?type=tenant&q=missing')->assertExactJson(['contacts' => []]);
        $this->getJson(self::PATH.'/leads?q=Sample')->assertExactJson(['leads' => []]);
        $this->getJson(self::PATH.'/contacts?type=lead&q=Sample')->assertUnprocessable();

        $public = $this->get('/accommodation/'.$property->slug)->assertOk()->inertiaProps()['property'];
        $this->assertArrayNotHasKey('property_owner_email', $public);
        $this->assertArrayNotHasKey('property_owner_name', $public);
    }

    public function test_accommodation_sends_have_private_searchable_history_and_attachment_downloads(): void
    {
        Mail::fake();
        Storage::fake('local');
        config(['mail.from.name' => 'Exalt Accommodation', 'mail.from.address' => 'exalt@example.test']);
        $property = $this->property();
        $staff = User::factory()->create(['role' => 'accommodation', 'name' => 'Accommodation staff']);
        $this->actingAs($staff);
        $contactId = 'owner:'.$property->id;
        $this->from(self::PATH)->post(self::PATH.'/send', [
            'contact_keys' => [$contactId], 'contact_addresses' => [$contactId => 'owner@example.test'],
            'emails' => ['OWNER@example.test', 'other@example.test'],
            'subject' => 'Viewing confirmation', 'body' => '<p>The viewing is at 11 am.</p>',
            'attachments' => [UploadedFile::fake()->create('Viewing details.pdf', 4, 'application/pdf')],
        ])->assertRedirect(self::PATH)->assertSessionHas('success')->assertSessionHasNoErrors();

        Mail::assertQueuedCount(2);
        Mail::assertQueued(TemplatedMessage::class, fn ($mail) => $mail->hasTo('owner@example.test') && $mail->fromEmail === 'exalt@example.test');
        $log = MessageLog::where('source', MessageLog::SOURCE_ACCOMMODATION_COMPOSE)->where('recipient_address', 'owner@example.test')->sole();
        $log->update(['status' => MessageLog::STATUS_SENT, 'sent_at' => now()]);
        $path = $log->compose_details['attachments'][0]['path'];
        Storage::disk('local')->assertExists($path);
        $other = MessageLog::create([
            'source' => 'compose', 'channel' => 'email', 'recipient_address' => 'owner@example.test',
            'subject' => 'Viewing confirmation from another portal', 'body' => 'Private other-portal message',
            'status' => 'sent', 'triggered_by_user_id' => $staff->id, 'compose_details' => $log->compose_details,
        ]);

        foreach (['sample owner', 'OWNER@example.test'] as $term) {
            $result = $this->get(self::PATH.'?sent_search='.urlencode($term))->assertOk()->inertiaProps()['sent'];
            $this->assertCount(1, $result);
            $message = $result[0];
            $this->assertSame($log->id, $message['id']);
            $this->assertSame('Viewing confirmation', $message['subject']);
            $this->assertSame('<p>The viewing is at 11 am.</p>', $message['body']);
            $this->assertSame('Exalt Accommodation', $message['from_name']);
            $this->assertSame('Accommodation staff', $message['sender']);
            $this->assertSame($log->fresh()->sent_at->toIso8601String(), $message['sent_at']);
            $this->assertSame('Viewing details.pdf', $message['attachments'][0]['name']);
            $this->assertStringNotContainsString($path, json_encode($message, JSON_THROW_ON_ERROR));
        }
        $this->get(self::PATH.'?sent_search=Viewing')->assertInertia(fn ($page) => $page->has('sent', 2));
        $this->get(self::PATH.'?sent_search=%25')->assertInertia(fn ($page) => $page->has('sent', 0));
        $this->get(self::PATH."/sent/{$log->id}/attachments/0")->assertDownload('Viewing details.pdf');
        $this->get(self::PATH."/sent/{$log->id}/attachments/1")->assertNotFound();
        $this->get(self::PATH."/sent/{$other->id}/attachments/0")->assertNotFound();

        $this->actingAs(User::factory()->create(['role' => 'education']));
        $this->get(self::PATH."/sent/{$log->id}/attachments/0")->assertForbidden();
        $this->getJson(self::PATH.'/contacts?type=owner')->assertForbidden();
        $this->get('/portal/education/compose')->assertInertia(fn ($page) => $page->has('sent', 1)->where('sent.0.id', $other->id));
    }

    public function test_accommodation_rejects_lead_ids_and_contacts_changed_since_selection(): void
    {
        Mail::fake();
        $property = $this->property();
        $lead = Lead::create(['first_name' => 'External', 'last_name' => 'Lead', 'email' => 'lead@example.test']);
        $this->actingAs(User::factory()->create(['role' => 'accommodation']));

        $this->postJson(self::PATH.'/send', ['lead_ids' => [$lead->id], 'body' => 'Test'])->assertUnprocessable()->assertJsonValidationErrors('lead_ids');
        $contactId = 'manager:'.$property->id;
        $this->postJson(self::PATH.'/send', [
            'contact_keys' => [$contactId], 'contact_addresses' => [$contactId => 'previous@example.test'], 'body' => 'Test',
        ])->assertUnprocessable()->assertJsonValidationErrors('contact_keys');
        Mail::assertNothingQueued();
        $this->assertDatabaseCount('message_logs', 0);
    }

    private function property(): Property
    {
        return Property::create([
            'name' => 'Example Property', 'address' => '10 Example Road', 'status' => 'available', 'is_active' => true,
            'property_manager_name' => 'Sample Manager', 'property_manager_email' => 'manager@example.test',
            'property_owner_name' => 'Sample Owner', 'property_owner_email' => 'owner@example.test',
        ]);
    }
}
