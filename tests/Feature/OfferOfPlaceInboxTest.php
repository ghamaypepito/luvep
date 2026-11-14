<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OfferOfPlaceInboxTest extends TestCase
{
    use RefreshDatabase;

    private function lead(array $attributes = []): Lead
    {
        return Lead::create(array_merge(['first_name' => 'Synthetic', 'last_name' => 'Student', 'is_student' => true], $attributes));
    }

    private function offer(Lead $lead, array $attributes = []): LeadDocument
    {
        $document = LeadDocument::create(array_merge([
            'lead_id' => $lead->id, 'checklist_key' => 'acad.offer_of_place', 'source' => 'upload',
            'original_name' => 'offer.pdf', 'file_path' => 'private/offer.pdf', 'status' => 'Submitted', 'mime' => 'application/pdf',
        ], $attributes));
        if (isset($attributes['created_at'])) {
            $document->forceFill(['created_at' => $attributes['created_at']])->save();
        }

        return $document;
    }

    public function test_all_uploaded_versions_are_shown_without_inferring_receipt_from_stages_or_fields(): void
    {
        $user = User::factory()->create(['role' => 'education']);
        $lead = $this->lead();
        $older = $this->offer($lead, ['created_at' => '2026-11-10 09:00:00']);
        $newer = $this->offer($lead, ['created_at' => '2026-11-12 09:00:00', 'status' => 'Approved']);
        $this->lead(['education_stage' => 'Unconditional Offer', 'student_oop' => 'Yes']);
        $this->offer($lead, ['checklist_key' => 'acad.cv']);
        $this->offer($lead, ['source' => 'generated']);
        $this->offer($lead, ['file_path' => null]);

        $this->actingAs($user)->get('/portal/education/offers-of-place')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('portal/education/OffersOfPlace')
                ->where('documents.total', 2)->where('clients', 1)
                ->where('documents.data.0.id', $newer->id)->where('documents.data.1.id', $older->id)
                ->where('documents.data.0.view_url', url('/portal/education/offers-of-place/'.$newer->id.'/download').'?inline=1'));
        $this->assertDatabaseCount('lead_documents', 5);
    }

    public function test_department_scoping_applies_to_lists_counts_and_direct_downloads(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('private/offer.pdf', 'synthetic offer');
        $student = $this->offer($this->lead());
        $sales = $this->offer($this->lead(['is_student' => false]));
        $case = $this->offer($this->lead(['is_student' => false, 'is_immigration_case' => true]));
        $education = User::factory()->create(['role' => 'education']);
        $salesUser = User::factory()->create(['role' => 'sales']);

        $this->actingAs($education)->get('/portal/education/offers-of-place')->assertInertia(fn (Assert $p) => $p
            ->where('documents.total', 1)->where('clients', 1)->where('documents.data.0.id', $student->id));
        $this->get('/portal/education/offers-of-place/'.$sales->id.'/download')->assertNotFound();
        $this->get('/portal/education/offers-of-place/'.$case->id.'/download')->assertNotFound();
        $this->get('/portal/education/offers-of-place/'.$student->id.'/download')->assertOk()->assertDownload('offer.pdf');
        $this->get('/portal/education/offers-of-place/'.$student->id.'/download?inline=1')->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($salesUser)->get('/portal/sales/offers-of-place')->assertInertia(fn (Assert $p) => $p
            ->component('portal/sales/OffersOfPlace')->where('documents.total', 1)->where('documents.data.0.id', $sales->id));
        $this->get('/portal/sales/offers-of-place/'.$student->id.'/download')->assertNotFound();
        $this->get('/portal/sales/offers-of-place/'.$case->id.'/download')->assertNotFound();
        $this->get('/portal/education/offers-of-place')->assertForbidden();
    }

    public function test_unrelated_roles_and_guests_cannot_open_the_view_or_download_files(): void
    {
        $doc = $this->offer($this->lead());
        $this->get('/portal/education/offers-of-place')->assertRedirect('/login');
        foreach (['lead', 'agent', 'finance', 'immigration', 'accommodation'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get('/admin/offers-of-place')->assertForbidden();
            $this->get('/portal/education/offers-of-place')->assertForbidden();
            $this->get('/portal/sales/offers-of-place/'.$doc->id.'/download')->assertForbidden();
        }
    }

    public function test_search_date_boundaries_and_pagination_preserve_uploaded_history(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $lead = $this->lead(['first_name' => 'Boundary']);
        foreach (['2026-11-09 23:59:59', '2026-11-10 00:00:00', '2026-11-10 23:59:59', '2026-11-11 00:00:00'] as $date) {
            $this->offer($lead, ['created_at' => $date]);
        }
        $this->offer($this->lead(['first_name' => 'Different']), ['created_at' => '2026-11-10 12:00:00']);
        $this->actingAs($user)->get('/admin/offers-of-place?search=Boundary&from=2026-11-10&to=2026-11-10')
            ->assertInertia(fn (Assert $p) => $p->component('admin/OffersOfPlace')->where('documents.total', 2)->where('clients', 1));
        $this->get('/admin/offers-of-place?to=2026-11-10')->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('documents.total', 4));
        $this->get('/admin/offers-of-place?from=2026-11-11&to=2026-11-10')->assertSessionHasErrors('to');
        $this->get('/admin/offers-of-place?from=invalid')->assertSessionHasErrors('from');

        for ($i = 0; $i < 27; $i++) {
            $this->offer($lead);
        }
        $this->get('/admin/offers-of-place?search=Boundary&page=2')->assertInertia(fn (Assert $p) => $p
            ->where('documents.total', 31)->has('documents.data', 6)->where('clients', 1)
            ->where('documents.current_page', 2));
    }

    public function test_download_rejects_non_offer_missing_and_generated_documents(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $lead = $this->lead();
        foreach ([['checklist_key' => 'acad.cv'], ['source' => 'generated'], ['file_path' => null]] as $attributes) {
            $doc = $this->offer($lead, $attributes);
            $this->get('/admin/offers-of-place/'.$doc->id.'/download')->assertNotFound();
        }
        $missing = $this->offer($lead, ['file_path' => 'not-present.pdf']);
        $this->get('/admin/offers-of-place/'.$missing->id.'/download')->assertNotFound();
    }
}
