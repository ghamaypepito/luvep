<?php

namespace Tests\Feature\Immigration;

use App\Models\ActivityLog;
use App\Models\CaseDependent;
use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\MediaFile;
use App\Models\MediaFolder;
use App\Models\User;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PassportPrefillTest extends TestCase
{
    use RefreshDatabase;

    private Lead $case;

    private User $staff;

    private LeadDocument $passport;

    private PassportPrefillFakeAi $ai;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\ExtractPassportDraft::class]);
        Storage::fake('local');
        Storage::fake('public');
        Http::preventStrayRequests();
        config(['ai.passport_prefill_enabled' => true, 'ai.passport_prefill_processing_approved' => true]);
        $this->case = Lead::create(['first_name' => 'Existing', 'last_name' => 'Synthetic', 'email' => 'synthetic@example.test', 'is_immigration_case' => true, 'dob' => '1980-01-01', 'passport_number' => 'OLD-SYNTHETIC']);
        $this->staff = User::factory()->create(['role' => 'immigration']);
        $this->passport = $this->document($this->case);
        $this->ai = new PassportPrefillFakeAi;
        $this->ai->content = json_encode(['full_name' => 'SYNTHETIC, SAMPLE', 'fields' => [
            'passport_number' => $this->field('NEW-SYNTHETIC'), 'dob' => $this->field('1990-02-01'),
            'passport_expiry' => $this->field('2030-01-01'), 'citizenship' => $this->field('Synthetic nationality'),
        ]]);
        $this->app->instance(AIService::class, $this->ai);
        $this->actingAs($this->staff);
    }

    private function field(string $value, float $confidence = 0.99): array
    {
        return ['value' => $value, 'confidence' => $confidence, 'page' => 1, 'evidence' => 'Synthetic printed value: '.$value];
    }

    private function url(?Lead $case = null): string
    {
        return '/portal/immigration/cases/'.($case ?? $this->case)->id.'/passport-prefill';
    }

    private function document(Lead $case, array $attributes = []): LeadDocument
    {
        $path = 'lead-documents/'.$case->id.'/'.uniqid('passport-').'.pdf';
        Storage::disk('local')->put($path, '%PDF synthetic passport fixture');

        return LeadDocument::create(array_merge(['lead_id' => $case->id, 'checklist_key' => 'passport', 'original_name' => 'synthetic-passport.pdf', 'file_path' => $path, 'mime' => 'application/pdf', 'source' => 'upload'], $attributes));
    }

    private function draft(): array
    {
        return $this->postJson($this->url())->assertOk()->json();
    }

    private function apply(array $draft, array $fields = ['passport_number'])
    {
        return $this->postJson($this->url().'/'.$draft['token'], ['confirmed' => true, 'fields' => $fields]);
    }

    public function test_extract_is_read_only_encrypted_and_includes_source_confidence_and_audit(): void
    {
        $before = $this->case->fresh()->getAttributes();
        $draft = $this->draft();
        $this->assertSame($before, $this->case->fresh()->getAttributes());
        $this->assertSame($this->passport->id, $draft['document']['id']);
        $this->assertSame('OLD-SYNTHETIC', collect($draft['fields'])->firstWhere('key', 'passport_number')['current']);
        $this->assertTrue(collect($draft['fields'])->firstWhere('key', 'passport_number')['can_apply']);
        $cached = Cache::get('passport-prefill:'.$this->staff->id.':'.$this->case->id.':'.$draft['token']);
        $this->assertIsString($cached);
        $this->assertStringNotContainsString('NEW-SYNTHETIC', $cached);
        $this->assertDatabaseHas('activity_logs', ['action' => 'passport_prefill.requested', 'entity_id' => $this->case->id, 'user_id' => $this->staff->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'passport_prefill.proposed', 'entity_id' => $this->case->id]);
        $this->get($draft['document']['url'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(1, $this->ai->calls);
    }

    public function test_only_reviewed_fields_are_saved_and_the_draft_is_single_use(): void
    {
        $draft = $this->draft();
        $this->case->update(['phone' => 'concurrently corrected phone']);
        $this->postJson($this->url().'/'.$draft['token'], ['confirmed' => true, 'fields' => ['passport_number'], 'passport_number' => 'CLIENT-INJECTED', 'first_name' => 'Unselected'])
            ->assertOk()->assertJsonPath('saved.passport_number', 'NEW-SYNTHETIC')->assertJsonPath('personal.phone', 'concurrently corrected phone');
        $fresh = $this->case->fresh();
        $this->assertSame('NEW-SYNTHETIC', $fresh->passport_number);
        $this->assertSame('Existing', $fresh->first_name);
        $this->assertSame('1980-01-01', $fresh->dob->format('Y-m-d'));
        $audit = ActivityLog::where('action', 'passport_prefill.applied')->sole();
        $this->assertSame($this->staff->id, $audit->user_id);
        $this->assertSame($this->passport->id, $audit->properties['document_id']);
        $this->assertSame(1, $audit->properties['accepted_fields']['passport_number']['page']);
        $this->apply($draft)->assertStatus(409);
        $this->assertSame(1, ActivityLog::where('action', 'passport_prefill.applied')->count());
    }

    public function test_confirmation_and_valid_field_selection_are_required(): void
    {
        $draft = $this->draft();
        $this->postJson($this->url().'/'.$draft['token'], ['fields' => ['passport_number']])->assertUnprocessable();
        $this->postJson($this->url().'/'.$draft['token'], ['confirmed' => true, 'fields' => ['email']])->assertUnprocessable();
        $this->postJson($this->url().'/'.$draft['token'], ['confirmed' => true, 'fields' => []])->assertUnprocessable();
        $this->assertSame('OLD-SYNTHETIC', $this->case->fresh()->passport_number);
    }

    public function test_low_confidence_and_missing_source_evidence_cannot_overwrite_values(): void
    {
        $this->ai->content = json_encode(['fields' => ['dob' => $this->field('1990-02-01', 0.4), 'passport_number' => ['value' => 'UNSOURCED', 'confidence' => 0.99]]]);
        $draft = $this->draft();
        $this->assertFalse($draft['fields'][0]['can_apply']);
        $this->assertFalse($draft['fields'][1]['can_apply']);
        $this->apply($draft, ['dob', 'passport_number'])->assertUnprocessable();
        $this->assertSame('OLD-SYNTHETIC', $this->case->fresh()->passport_number);
        $this->assertSame('1980-01-01', $this->case->fresh()->dob->format('Y-m-d'));
    }

    public function test_invalid_dates_and_ambiguous_names_are_not_guessed(): void
    {
        $this->ai->content = json_encode(['full_name' => 'SURNAME, COMBINED GIVEN NAMES', 'fields' => [
            'first_name' => $this->field('GUESSED'), 'middle_name' => ['value' => null], 'dob' => $this->field('2030-02-31'),
            'passport_expiry' => $this->field('not a date'), 'citizenship' => ['value' => ['malformed']], 'passport_number' => $this->field('VALID-SYNTHETIC'),
        ]]);
        $draft = $this->draft();
        $this->assertSame(['passport_number'], array_column($draft['fields'], 'key'));
        $this->apply($draft, ['first_name'])->assertUnprocessable();
        $this->assertSame('Existing', $this->case->fresh()->first_name);
    }

    public function test_malformed_or_unreadable_model_result_saves_nothing(): void
    {
        foreach (['not JSON', '{"fields":{}}', '{"fields":{"dob":{"value":[]}}}'] as $raw) {
            $this->ai->content = $raw;
            $this->postJson($this->url())->assertUnprocessable();
        }
        $this->assertSame('OLD-SYNTHETIC', $this->case->fresh()->passport_number);
        $this->assertSame(0, ActivityLog::where('action', 'passport_prefill.applied')->count());
    }

    public function test_changed_case_values_source_bytes_or_newer_passport_reject_the_draft(): void
    {
        $draft = $this->draft();
        $this->case->update(['passport_number' => 'NEWER-STAFF-EDIT']);
        $this->apply($draft)->assertStatus(409);
        $this->assertSame('NEWER-STAFF-EDIT', $this->case->fresh()->passport_number);
        $draft = $this->draft();
        Storage::disk('local')->put($this->passport->file_path, '%PDF replacement bytes');
        $this->apply($draft)->assertStatus(409);
        $draft = $this->draft();
        $this->document($this->case);
        $this->apply($draft)->assertStatus(409);
        $this->assertSame(0, ActivityLog::where('action', 'passport_prefill.applied')->count());
    }

    public function test_drafts_are_bound_to_the_actor_case_and_expire(): void
    {
        $draft = $this->draft();
        $otherCase = Lead::create(['first_name' => 'Other', 'last_name' => 'Synthetic', 'is_immigration_case' => true]);
        $this->postJson($this->url($otherCase).'/'.$draft['token'], ['confirmed' => true, 'fields' => ['passport_number']])->assertStatus(409);
        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']));
        $this->apply($draft)->assertStatus(409);
        $this->actingAs($this->staff);
        $this->travel(16)->minutes();
        $this->apply($draft)->assertStatus(409);
        $this->travelBack();
        $this->assertSame('OLD-SYNTHETIC', $this->case->fresh()->passport_number);
    }

    public function test_public_only_unsupported_and_oversized_files_never_reach_the_provider(): void
    {
        Storage::disk('public')->put($this->passport->file_path, '%PDF public only');
        Storage::disk('local')->delete($this->passport->file_path);
        $this->postJson($this->url())->assertUnprocessable();
        Storage::disk('local')->put($this->passport->file_path, '%PDF private');
        $this->passport->update(['mime' => 'text/plain']);
        $this->postJson($this->url())->assertUnprocessable();
        $this->passport->update(['mime' => 'application/pdf']);
        Storage::disk('local')->put($this->passport->file_path, str_repeat('x', 10 * 1024 * 1024 + 1));
        $this->postJson($this->url())->assertUnprocessable();
        $this->assertSame(0, $this->ai->calls);
    }

    public function test_primary_passport_is_used_and_foreign_or_dependent_sources_are_denied(): void
    {
        $dependent = CaseDependent::create(['lead_id' => $this->case->id, 'first_name' => 'Synthetic', 'family_name' => 'Child', 'relationship' => 'child']);
        $sponsor = $this->document($this->case, ['checklist_key' => 'spfin.passport']);
        $partner = $this->document($this->case, ['checklist_key' => 'part.passport']);
        $generated = $this->document($this->case, ['source' => 'generated']);
        $depDoc = $this->document($this->case, ['dependent_id' => $dependent->id]);
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Synthetic', 'is_immigration_case' => true]);
        $foreign = $this->document($other);
        $draft = $this->draft();
        $this->assertSame($this->passport->id, $draft['document']['id']);
        foreach ([$depDoc, $foreign, $sponsor, $partner, $generated] as $doc) {
            $this->get($this->url().'/document/'.$doc->id)->assertNotFound();
        }
        $this->passport->delete();
        $this->postJson($this->url())->assertNotFound();
        $this->assertSame(1, $this->ai->calls);
    }

    public function test_media_drive_cross_link_cannot_send_another_clients_file(): void
    {
        $other = Lead::create(['first_name' => 'Other', 'last_name' => 'Synthetic']);
        $folder = MediaFolder::create(['lead_id' => $other->id, 'client_key' => 'lead:'.$other->id, 'name' => 'Private']);
        $file = MediaFile::create(['folder_id' => $folder->id, 'original_name' => 'passport.pdf', 'file_path' => $this->passport->file_path, 'mime' => 'application/pdf', 'size' => 30, 'sha256' => hash('sha256', 'synthetic'), 'upload_key' => (string) \Illuminate\Support\Str::uuid(), 'actor_key' => 'user:'.$this->staff->id]);
        $this->passport->update(['media_file_id' => $file->id]);
        $this->postJson($this->url())->assertNotFound();
        $this->assertSame(0, $this->ai->calls);
    }

    public function test_feature_approval_and_ai_switches_prevent_processing(): void
    {
        config(['ai.passport_prefill_enabled' => false]);
        $this->postJson($this->url())->assertForbidden();
        config(['ai.passport_prefill_enabled' => true, 'ai.passport_prefill_processing_approved' => false]);
        $this->postJson($this->url())->assertForbidden();
        config(['ai.passport_prefill_processing_approved' => true]);
        $this->ai->enabled = false;
        $this->postJson($this->url())->assertUnprocessable();
        $this->ai->enabled = true;
        $this->ai->configured = false;
        $this->postJson($this->url())->assertUnprocessable();
        $this->assertSame(0, $this->ai->calls);
    }

    public function test_unrelated_roles_non_cases_and_cross_role_drafts_are_denied(): void
    {
        $draft = $this->draft();
        foreach (['lead', 'agent', 'sub_agent', 'sales', 'education', 'finance', 'accommodation'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->postJson($this->url())->assertForbidden();
            $this->get($draft['document']['url'])->assertForbidden();
            $this->apply($draft)->assertForbidden();
        }
        $this->actingAs($this->staff);
        $plain = Lead::create(['first_name' => 'Plain', 'last_name' => 'Synthetic']);
        $this->postJson($this->url($plain))->assertNotFound();
        $this->assertSame(1, $this->ai->calls);
    }

    public function test_a_newer_extraction_supersedes_an_old_draft_and_zero_is_not_a_source_id(): void
    {
        $first = $this->draft();
        $this->draft();
        $this->apply($first)->assertStatus(409);
        $this->get($this->url().'/document/0')->assertNotFound();
        $this->assertSame('OLD-SYNTHETIC', $this->case->fresh()->passport_number);
    }

    public function test_existing_staff_roles_can_review_and_default_configuration_is_off(): void
    {
        foreach (['admin', 'super_admin', 'immigration_manager', 'immigration_adviser'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->draft();
        }
        $config = require base_path('config/ai.php');
        $this->assertFalse($config['passport_prefill_enabled']);
        $this->assertFalse($config['passport_prefill_processing_approved']);
    }
}

class PassportPrefillFakeAi extends AIService
{
    public string $content = '';

    public bool $enabled = true;

    public bool $configured = true;

    public int $calls = 0;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function configured(): bool
    {
        return $this->configured;
    }

    public function chat(array $messages, ?string $model = null): array
    {
        $this->calls++;

        return ['content' => $this->content, 'model' => 'synthetic-test', 'tokens' => 10];
    }
}
