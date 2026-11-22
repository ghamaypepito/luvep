<?php

namespace Tests\Feature\Education;

use App\Models\{ActivityLog, Lead, LeadDocument, LeadNote, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Artisan, Bus, Crypt, Http, Mail, Notification, Storage};
use Tests\TestCase;

class DuplicateRepairTest extends TestCase
{
    use RefreshDatabase;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake(); Mail::fake(); Notification::fake(); Http::preventStrayRequests();
        Storage::fake('local'); Storage::fake('public');
        $this->actor = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        foreach ([402, 512] as $id) {
            $lead = new Lead(['first_name' => 'Synthetic', 'last_name' => 'Duplicate', 'is_student' => true,
                'is_immigration_case' => true, 'education_stage' => $id === 402 ? 'Started Course' : null,
                'immigration_stage' => $id === 512 ? 'Approved Visa' : null, 'email' => "record{$id}@example.test",
                'dob' => $id === 512 ? '1990-01-01' : null, 'passport_number' => $id === 512 ? 'SYNTHETIC' : null]);
            $lead->id = $id; $lead->saveQuietly();
            $lead->studyPlans()->create(['preferred_course' => 'Synthetic degree', 'qualification_level' => '7', 'preferred_intake' => '2026-10-31']);
            $lead->documents()->create(['checklist_key' => 'acad.offer_of_place', 'original_name' => 'synthetic-offer.pdf',
                'file_path' => "lead-documents/{$id}/offer.pdf", 'source' => 'upload', 'status' => 'Submitted']);
            Storage::disk('local')->put("lead-documents/{$id}/offer.pdf", 'identical synthetic personalized offer');
        }
        User::factory()->create(['role' => 'lead', 'lead_id' => 512]);
    }

    private function digest(): string
    {
        $this->assertSame(0, Artisan::call('education:repair-duplicate-ntujat'));
        $this->assertSame(1, preg_match('/Plan SHA256: ([a-f0-9]{64})/', Artisan::output(), $match));
        return $match[1];
    }

    private function apply(string $digest): int
    {
        return Artisan::call('education:repair-duplicate-ntujat', ['--apply' => $digest, '--actor' => $this->actor->id]);
    }

    public function test_repair_is_previewed_encrypted_audited_and_repeatable_without_email(): void
    {
        $before = Lead::findOrFail(402)->getAttributes();
        $digest = $this->digest();
        $this->assertSame($digest, $this->digest());
        $this->assertDatabaseCount('leads', 2);
        $this->assertSame(1, Lead::find(402)->documents()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('repairs'));
        $this->assertSame(0, $this->apply($digest), Artisan::output());
        $this->assertNull(Lead::find(402));
        $this->assertNotNull(Lead::withTrashed()->find(402));
        $target = Lead::findOrFail(512);
        $this->assertSame('Started Course', $target->education_stage);
        $this->assertSame('Approved Visa', $target->immigration_stage);
        $this->assertSame('record512@example.test', $target->email);
        $this->assertSame(2, $target->documents()->count());
        $this->assertSame(1, $target->studyPlans()->count());
        $this->assertSame(1, Lead::withTrashed()->find(402)->studyPlans()->count());
        $this->assertSame(512, User::where('role', 'lead')->sole()->lead_id);
        $files = Storage::disk('local')->allFiles('repairs');
        $this->assertCount(1, $files);
        $snapshot = json_decode(Crypt::decryptString(Storage::disk('local')->get($files[0])), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($before, $snapshot['source']);
        $this->assertSame(0600, fileperms(Storage::disk('local')->path($files[0])) & 0777);
        $this->assertSame(0, $this->apply($digest));
        $this->assertSame(1, ActivityLog::where('action', 'lead.education_duplicate_merged')->count());
        $this->assertSame(1, LeadNote::where('lead_id', 512)->count());
        Storage::disk('local')->assertExists('lead-documents/402/offer.pdf');
        Storage::disk('local')->assertExists('lead-documents/512/offer.pdf');
        Mail::assertNothingSent(); Mail::assertNothingQueued(); Bus::assertNothingDispatched(); Notification::assertNothingSent();
    }

    public function test_stale_preview_and_unknown_relationship_prevent_mutation(): void
    {
        $digest = $this->digest();
        Lead::whereKey(512)->update(['phone' => 'changed']);
        $this->assertSame(1, $this->apply($digest));
        $this->assertNotNull(Lead::find(402));
        $digest = $this->digest();
        Lead::find(402)->notes()->create(['body' => 'New material information']);
        $this->assertSame(1, $this->apply($digest));
        $this->assertStringContainsString('Unreviewed source relationship: lead_notes.lead_id', Artisan::output());
        $this->assertSame(1, Lead::find(402)->documents()->count());
    }

    public function test_failed_audit_rolls_back_the_entire_repair(): void
    {
        $digest = $this->digest();
        ActivityLog::creating(function ($log) { if ($log->action === 'lead.education_duplicate_merged') throw new \RuntimeException('Synthetic audit failure'); });
        $this->assertSame(1, $this->apply($digest));
        $this->assertNotNull(Lead::find(402));
        $this->assertNull(Lead::find(512)->education_stage);
        $this->assertSame(1, Lead::find(402)->documents()->count());
        $this->assertSame(1, Lead::find(512)->documents()->count());
        $this->assertSame(0, LeadNote::count());
    }

    public function test_portal_account_reassignment_invalidates_preview(): void
    {
        $digest = $this->digest();
        User::where('lead_id', 512)->update(['lead_id' => null]);
        User::factory()->create(['role' => 'lead', 'lead_id' => 512]);
        $this->assertSame(1, $this->apply($digest));
        $this->assertStringContainsString('Dry-run digest changed', Artisan::output());
        $this->assertNotNull(Lead::find(402));
        $this->assertSame(1, Lead::find(402)->documents()->count());
    }

    public function test_multiple_or_non_client_portal_owners_prevent_preview(): void
    {
        $extra = User::factory()->create(['role' => 'lead', 'lead_id' => 512]);
        $this->assertSame(1, Artisan::call('education:repair-duplicate-ntujat'));
        $this->assertStringContainsString('Portal account ownership changed', Artisan::output());
        $extra->delete();
        User::where('lead_id', 512)->update(['role' => 'education']);
        $this->assertSame(1, Artisan::call('education:repair-duplicate-ntujat'));
        $this->assertNotNull(Lead::find(402));
    }

    public function test_nonmatching_offer_prevents_repair(): void
    {
        Storage::disk('local')->put('lead-documents/402/offer.pdf', 'different offer');
        $this->assertSame(1, Artisan::call('education:repair-duplicate-ntujat'));
        $this->assertStringContainsString('offer no longer matches', Artisan::output());
        $this->assertNotNull(Lead::find(402));
    }
}
