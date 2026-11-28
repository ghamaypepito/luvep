<?php

namespace Tests\Feature;

use App\Mail\TemplatedMessage;
use App\Models\AccommodationAgreement;
use App\Models\AccommodationViewingSlot;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use App\Models\PreTenancyForm;
use App\Models\Property;
use App\Models\TemplateFolder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accommodation\ExaltEmailTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ExaltEmailTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private const COMPOSE = '/portal/accommodation/compose';

    private const LIBRARY = '/portal/accommodation/email-templates';

    private const EMAIL = 'client@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now('Pacific/Auckland')->setDate(2026, 11, 27)->setTime(10, 0));
        Mail::fake();
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'accommodation', 'is_active' => true]));
    }

    public function test_accommodation_library_and_compose_show_the_approved_order_and_scope(): void
    {
        $other = MessageTemplate::create([
            'department' => 'education', 'name' => 'Education only', 'key' => 'education_only',
            'channels' => ['email'], 'email_subject' => 'Education only', 'email_body' => 'Body',
        ]);
        $folder = TemplateFolder::create(['department' => 'education', 'name' => 'Education folder']);
        $accFolder = TemplateFolder::create(['department' => 'accommodation', 'name' => 'Accommodation folder']);
        $fixture = json_decode(file_get_contents(database_path('data/exalt-accommodation-email-templates.json')), true, flags: JSON_THROW_ON_ERROR);
        $expected = array_column($fixture, 'name');

        $this->get(self::LIBRARY)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('folders', 1)->where('folders.0.id', $accFolder->id)
            ->where('departmentOptions', []));
        $library = $this->get(self::LIBRARY)->inertiaProps('templates');
        $this->assertSame($expected, array_column(array_slice($library, 0, 8), 'name'));
        $this->assertSame(['accommodation'], array_values(array_unique(array_column($library, 'department'))));
        $compose = $this->get(self::COMPOSE)->assertOk()->inertiaProps('templates');
        $this->assertSame($expected, array_column(array_slice($compose, 0, 8), 'name'));
        $this->assertSame(array_column($fixture, 'subject'), array_column(array_slice($compose, 0, 8), 'email_subject'));
        $this->assertContains('Bond amount', array_column($compose[3]['exalt']['fields'], 'key'));
        $this->assertContains('Deposit amount', array_column($compose[3]['exalt']['fields'], 'key'));
        $this->get(self::LIBRARY.'/'.$other->id)->assertNotFound();
        $this->post(self::LIBRARY.'/delete', ['ids' => [$other->id]])->assertNotFound();
        $this->delete(self::LIBRARY.'/folders/'.$folder->id)->assertNotFound();
        $this->assertDatabaseHas('message_templates', ['id' => $other->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('template_folders', ['id' => $folder->id]);

        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->get('/portal/education/email-templates')->assertOk()->assertInertia(fn (Assert $page) => $page->has('templates'));
        $this->get(self::COMPOSE.'/exalt-template/options')->assertForbidden();
    }

    public function test_booking_link_and_attendance_actions_use_portal_records(): void
    {
        $property = Property::create(['name' => 'Sample House', 'address' => '97 Sample Avenue']);
        $slot = AccommodationViewingSlot::create([
            'property_id' => $property->id, 'property_address' => $property->address,
            'viewing_date' => '2026-11-30', 'start_time' => '11:00', 'capacity' => 2, 'blocked' => false,
        ]);
        $attendee = $slot->attendees()->create(['name' => 'Sample Client', 'email' => self::EMAIL, 'mobile' => '0211234567']);
        $this->send(2, $property->id);
        $link = $this->button(0, 'Book a Room / Property Viewing');
        $this->get($link)->assertOk()->assertInertia(fn (Assert $page) => $page->where('address', $property->address)->has('slots', 1));

        $this->postJson(self::COMPOSE.'/exalt-template/preview', [
            ...$this->payload(3, $attendee->id), 'email' => 'someone-else@example.test',
        ])->assertUnprocessable()->assertJsonValidationErrors('action_id');
        $this->send(3, $attendee->id);
        $confirm = $this->button(1, 'Confirm Attendance');
        $decline = $this->button(1, 'Decline Attendance');
        $this->get($confirm)->assertOk();
        $this->assertNull($attendee->fresh()->attendance_response);
        $this->post($decline)->assertOk();
        $this->assertSame('declined', $attendee->fresh()->attendance_response);
        $this->post($confirm)->assertOk();
        $this->assertSame('confirmed', $attendee->fresh()->attendance_response);
        $this->get('/portal/accommodation/viewings?schedule_date=2026-11-30')
            ->assertInertia(fn (Assert $page) => $page->where('sharedSchedule.slots.0.attendees.0.attendance_response', 'confirmed'));
        $this->getJson('/portal/accommodation/calendar/events?start=2026-11-30&end=2026-11-30')
            ->assertJsonPath('0.meta.attendees.0.attendance_response', 'confirmed');
    }

    public function test_proposal_signing_and_signed_download_links_use_the_selected_record(): void
    {
        $proposal = $this->payload(4, 0);
        $proposal['fields']['Bond amount'] = 'NZ$100';
        $proposal['fields']['Deposit amount'] = 'NZ$200';
        $preview = $this->postJson(self::COMPOSE.'/exalt-template/preview', $proposal)->assertOk()->json();
        $this->assertStringContainsString('bond — NZ$100', $preview['body']);
        $this->assertStringContainsString('deposit — NZ$200', $preview['body']);
        $this->post(self::COMPOSE.'/exalt-template/send', $proposal)->assertSessionHasNoErrors();
        $this->get($this->button(0, 'Complete the Pre-Tenancy Form'))->assertOk();

        $source = PreTenancyForm::create(['email' => self::EMAIL, 'full_legal_name' => 'Sample Client']);
        $agreement = AccommodationAgreement::create([
            'agreement_type' => 'standard_flatmate', 'pre_tenancy_form_id' => $source->id,
            'client_name' => 'Sample Client', 'property_address' => '97 Sample Avenue',
            'status' => 'sent', 'signing_token' => str_repeat('t', 48), 'data' => [],
        ]);
        $this->send(5, $agreement->id);
        $this->assertSame($agreement->signingUrl(), $this->button(1, 'Review and Sign Agreement'));

        $agreement->update(['status' => 'signed', 'signed_pdf_path' => 'sample-signed.pdf']);
        Storage::disk('local')->put('sample-signed.pdf', '%PDF-sample');
        $this->send(6, $agreement->id);
        $this->get($this->button(2, 'Download Signed Agreement'))->assertDownload('sample-client-signed.pdf');
        Mail::assertQueuedCount(3);
    }

    public function test_registration_and_welcome_validate_free_fields_and_attachments(): void
    {
        $draft = $this->payload(1);
        $draft['fields']['First Name'] = '<b>Alex</b>';
        $draft['fields']['Property Name'] = 'Sample House';
        $preview = $this->postJson(self::COMPOSE.'/exalt-template/preview', $draft)->assertOk()
            ->assertJsonPath('subject', 'We’ve received your registration for Sample House')->json('body');
        $this->assertStringContainsString('&lt;b&gt;Alex&lt;/b&gt;', $preview);
        $this->assertStringNotContainsString('[First Name]', $preview);
        $this->post(self::COMPOSE.'/exalt-template/send', $draft)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('message_logs', [
            'source' => MessageLog::SOURCE_ACCOMMODATION_COMPOSE,
            'recipient_address' => self::EMAIL,
            'subject' => 'We’ve received your registration for Sample House',
        ]);
        Mail::assertQueued(TemplatedMessage::class, fn (TemplatedMessage $mail) => $mail->replyToEmail === 'exaltinfo@luvep.com');
        $property = Property::create(['name' => 'Sample House', 'address' => '97 Sample Avenue']);
        $tenant = Tenant::create([
            'property_id' => $property->id, 'first_name' => 'Sample', 'family_name' => 'Client',
            'current_status' => 'active', 'contract_type' => 'fixed_term', 'email' => self::EMAIL,
        ]);
        $welcome = $this->payload(8, $tenant->id);
        $this->postJson(self::COMPOSE.'/exalt-template/send', $welcome)->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $this->post(self::COMPOSE.'/exalt-template/send', [
            ...$welcome, 'attachments' => [UploadedFile::fake()->create('move-in-report.pdf', 5, 'application/pdf')],
        ])->assertSessionHasNoErrors();
        Mail::assertQueuedCount(2);
    }

    public function test_move_in_invoice_link_is_recipient_scoped_when_invoice_storage_is_installed(): void
    {
        if (! Schema::hasTable('accommodation_rental_invoices')) {
            $this->getJson(self::COMPOSE.'/exalt-template/options?'.http_build_query([
                'template_id' => $this->template(7)->id, 'email' => self::EMAIL,
            ]))->assertOk()->assertExactJson(['options' => []]);

            return;
        }

        $property = Property::create(['name' => 'Sample House', 'address' => '97 Sample Avenue']);
        $tenant = Tenant::create([
            'property_id' => $property->id, 'first_name' => 'Sample', 'family_name' => 'Client',
            'current_status' => 'active', 'contract_type' => 'fixed_term', 'email' => self::EMAIL,
        ]);
        $invoice = DB::table('accommodation_rental_invoices')->insertGetId([
            'tenant_id' => $tenant->id, 'kind' => 'move-in', 'reference' => 'EXALT-MI-SAMPLE',
            'submission_key' => (string) \Illuminate\Support\Str::uuid(), 'submission_hash' => str_repeat('a', 64),
            'pdf_path' => 'test-invoice.pdf', 'details' => json_encode(['property' => '97 Sample Avenue', 'due_date' => '2026-12-02', 'total_cents' => 124500]),
        ]);
        Storage::disk('local')->put('test-invoice.pdf', '%PDF-sample');
        $this->postJson(self::COMPOSE.'/exalt-template/send', [...$this->payload(7, $invoice), 'email' => 'other@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('action_id');
        $this->send(7, $invoice);
        $link = $this->button(0, 'View or Download Invoice');
        $this->get($link)->assertDownload('EXALT-MI-SAMPLE.pdf');
        $tenant->update(['email' => 'changed@example.test']);
        $this->get($link)->assertNotFound();
    }

    private function template(int $number): MessageTemplate
    {
        return MessageTemplate::where('key', sprintf('exalt_client_%02d', $number))->sole();
    }

    private function payload(int $number, ?int $action = null): array
    {
        $template = $this->template($number);
        $fields = collect(app(ExaltEmailTemplates::class)->metadata($template)['fields'])
            ->mapWithKeys(fn ($field) => [$field['key'] => 'Sample value'])->all();

        return ['template_id' => $template->id, 'email' => self::EMAIL, 'action_id' => $action, 'fields' => $fields];
    }

    private function send(int $number, ?int $action = null): void
    {
        $this->post(self::COMPOSE.'/exalt-template/send', $this->payload($number, $action))->assertSessionHasNoErrors();
    }

    private function button(int $index, string $label): string
    {
        $emails = Mail::queued(TemplatedMessage::class)->values();
        preg_match('/href="([^"]+)">'.preg_quote($label, '/').'<\/a>/', $emails[$index]->markdownBody, $match);
        $this->assertNotEmpty($match, $label);

        return html_entity_decode($match[1]);
    }
}
