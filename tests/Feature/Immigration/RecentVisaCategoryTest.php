<?php

namespace Tests\Feature\Immigration;

use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Models\VisaType;
use App\Services\Immigration\CaseChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecentVisaCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Mail::fake();
        Notification::fake();
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
    }

    private function caseRecord(array $attributes = []): Lead
    {
        return Lead::create(array_merge(['first_name' => 'Synthetic', 'last_name' => 'Category', 'is_immigration_case' => true,
            'inz_visa_type' => 'Synthetic Category Visa', 'document_checklist' => [['key' => 'passport', 'label' => 'Passport', 'required' => true]]], $attributes));
    }

    private function url(Lead $lead): string
    {
        return '/portal/immigration/cases/'.$lead->id.'/documents/recent-visa-category';
    }

    public function test_empty_category_is_visible_and_accepts_client_upload_without_changing_any_existing_file_or_other_case(): void
    {
        $visa = VisaType::create(['code' => 'CATEGORY', 'name' => 'Synthetic Category Visa', 'category' => 'Resident', 'active' => true,
            'checklist_items' => [['key' => 'passport', 'label' => 'Passport', 'required' => true]]]);
        $lead = $this->caseRecord(['document_checklist' => null, 'hidden_track_documents' => ['legacy.private']]);
        $other = $this->caseRecord();
        $document = LeadDocument::create(['lead_id' => $lead->id, 'original_name' => 'Private original.pdf', 'file_path' => 'private/original.pdf', 'mime' => 'application/pdf', 'size' => 20,
            'status' => 'Approved', 'source' => 'upload', 'checklist_key' => 'legacy.private', 'note' => 'Preserve review', 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
        Storage::disk('local')->put($document->file_path, '%PDF-1.4 existing private bytes');
        $beforeDocument = $document->fresh()->getRawOriginal();
        $beforeOther = $other->fresh()->getRawOriginal();
        $beforeVisa = $visa->fresh()->getRawOriginal();
        $resolver = app(CaseChecklistService::class);
        $required = $resolver->progress($lead);
        $this->post($this->url($lead))->assertRedirect()->assertSessionHasNoErrors();
        $lead->refresh();
        $item = collect($resolver->forCase($lead))->firstWhere('key', 'recent_visa');
        $this->assertSame('Recent visa / eVisa', $item['label']);
        $this->assertSame('Visa applications & decisions', $item['category']);
        $this->assertFalse($item['required']);
        $this->assertNotContains('recent_visa', $resolver->hiddenKeysFor($lead));
        $this->assertSame(['legacy.private'], $lead->hidden_track_documents);
        $this->assertSame(0, $lead->documents()->where('checklist_key', 'recent_visa')->count());
        $this->assertSame($beforeDocument, $document->fresh()->getRawOriginal());
        $this->assertSame($beforeOther, $other->fresh()->getRawOriginal());
        $this->assertSame($beforeVisa, $visa->fresh()->getRawOriginal());
        foreach (['required_total', 'required_approved'] as $key) {
            $this->assertSame($required[$key], $resolver->progress($lead)[$key]);
        }
        $this->assertSame('%PDF-1.4 existing private bytes', Storage::disk('local')->get($document->file_path));
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();

        $client = User::factory()->create(['role' => 'lead', 'lead_id' => $lead->id]);
        $this->actingAs($client)->get('/portal/lead/documents')->assertOk()->assertInertia(fn ($page) => $page
            ->where('checklist.Visa applications & decisions.0.key', 'recent_visa'));
        $this->get('/portal/lead/documents/'.$document->id.'/download')->assertNotFound();
        $this->post('/portal/lead/documents/checklist/recent_visa/upload', ['files' => [UploadedFile::fake()->create('new-evisa.pdf', 5, 'application/pdf')]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Submitted', $lead->documents()->where('checklist_key', 'recent_visa')->sole()->status);
        $this->assertSame($beforeDocument, $document->fresh()->getRawOriginal());
    }

    public function test_category_creation_and_audit_are_idempotent_and_retry_never_unhides_an_existing_row(): void
    {
        $lead = $this->caseRecord();
        $actor = auth()->id();
        $this->post($this->url($lead))->assertRedirect()->assertSessionHasNoErrors();
        $event = ActivityLog::where('action', 'case.document.category_added')->sole();
        $this->assertSame($actor, $event->user_id);
        $this->assertSame($lead->id, $event->properties['subject_id']);
        $this->assertSame('recent_visa', $event->properties['checklist_key']);
        $lead->refresh()->update(['hidden_track_documents' => ['recent_visa']]);
        $before = $lead->fresh()->getRawOriginal();
        $this->post($this->url($lead))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, $lead->fresh()->getRawOriginal());
        $this->assertSame(1, ActivityLog::where('action', 'case.document.category_added')->count());
        $this->assertSame(1, collect(app(CaseChecklistService::class)->forCase($lead))->where('key', 'recent_visa')->count());
        Mail::assertNothingOutgoing();
        Notification::assertNothingSent();
    }

    public function test_existing_catalogue_category_is_not_overridden_or_unhidden(): void
    {
        VisaType::create(['code' => 'CATEGORY', 'name' => 'Synthetic Category Visa', 'category' => 'Resident', 'active' => true,
            'checklist_items' => [['key' => 'recent_visa', 'label' => 'Existing recent visa label', 'required' => false]]]);
        $lead = $this->caseRecord(['document_checklist' => null, 'hidden_track_documents' => ['recent_visa']]);
        $before = $lead->fresh()->getRawOriginal();
        $this->post($this->url($lead))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($before, $lead->fresh()->getRawOriginal());
        $this->assertSame(0, ActivityLog::where('action', 'case.document.category_added')->count());
    }

    public function test_missing_category_with_existing_files_or_hidden_policy_cannot_publish_them(): void
    {
        $lead = $this->caseRecord();
        $document = LeadDocument::create(['lead_id' => $lead->id, 'original_name' => 'Existing visa.pdf', 'file_path' => 'private/existing.pdf', 'source' => 'upload', 'status' => 'Submitted', 'checklist_key' => 'recent_visa']);
        $before = $lead->fresh()->getRawOriginal();
        $this->post($this->url($lead))->assertSessionHasErrors('category');
        $this->assertSame($before, $lead->fresh()->getRawOriginal());
        $this->assertSame('recent_visa', $document->fresh()->checklist_key);
        $other = $this->caseRecord(['hidden_track_documents' => ['recent_visa']]);
        $this->post($this->url($other))->assertSessionHasErrors('category');
        $this->assertNotContains('recent_visa', array_column(app(CaseChecklistService::class)->forCase($other->fresh()), 'key'));
    }

    public function test_only_active_internal_immigration_roles_can_add_to_a_real_case(): void
    {
        $lead = $this->caseRecord();
        foreach (['sales', 'education', 'lead', 'agent', 'finance'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['immigration']]))->post($this->url($lead))->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager', 'is_active' => false]))->post($this->url($lead))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']))->post($this->url($this->caseRecord(['is_immigration_case' => false])))->assertNotFound();
        $this->assertNotContains('recent_visa', array_column(app(CaseChecklistService::class)->forCase($lead->fresh()), 'key'));
    }
}
