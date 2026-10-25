<?php

namespace Tests\Feature;

use App\Models\EducationVisit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EducationSchoolLogoTest extends TestCase
{
    use RefreshDatabase;

    private function visit(): EducationVisit
    {
        return EducationVisit::create(['slug' => 'logo-school', 'school' => 'School with a long official university name', 'kind' => 'school', 'activity' => 'Education event', 'city' => 'Davao', 'status' => 'open', 'timezone' => 'Asia/Manila', 'schedule_version' => 7, 'logo_path' => '/education-rsvp/assets/brokenshire-logo.png']);
    }

    public function test_education_staff_can_replace_logo_and_publish_it_without_resending_schedule_notices(): void
    {
        Storage::fake('public');
        $visit = $this->visit();
        $staff = User::factory()->create(['role' => 'education']);
        $this->actingAs($staff);
        $url = '/portal/education/rsvp-visits/'.$visit->id.'/logo';
        $this->post($url, ['logo' => UploadedFile::fake()->image('school.png', 300, 300)])->assertRedirect();
        $first = $visit->fresh()->logo_path;
        $this->assertNotSame('/education-rsvp/assets/brokenshire-logo.png', $first);
        $this->post($url, ['logo' => UploadedFile::fake()->image('replacement.jpg', 400, 200)])->assertRedirect();
        $logo = $visit->fresh()->logo_path;
        $this->assertNotSame($first, $logo);
        $this->assertCount(2, Storage::disk('public')->allFiles('education/school-logos/'.$visit->id));
        $this->assertSame(7, $visit->fresh()->schedule_version);
        $this->assertFalse($visit->fresh()->schedule_confirmed);
        $this->assertDatabaseCount('education_outreach', 0);
        $this->getJson('/education/rsvp/catalogue')->assertJsonPath('visits.0.logo', $logo);
        $html = $this->get('/education/rsvp')->assertOk()->getContent();
        $dom = new \DOMDocument;
        $prior = libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($prior);
        $images = (new \DOMXPath($dom))->query('//button[@data-visit="'.$visit->id.'"]//img');
        $this->assertCount(1, $images, 'The school logo must be visible in the event row, not only its JSON data.');
        $this->assertSame($logo, $images->item(0)->getAttribute('src'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'education.rsvp.logo_updated']);
    }

    public function test_manager_opens_as_a_full_page_after_inertia_sign_in(): void
    {
        $staff = User::factory()->create(['role' => 'education']);
        $version = app(\App\Http\Middleware\HandleInertiaRequests::class)->version(\Illuminate\Http\Request::create('/portal/education/rsvps'));
        $this->actingAs($staff)->get('/portal/education/rsvps', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version ?? ''])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', url('/portal/education/rsvps'));
    }

    public function test_logo_upload_is_restricted_to_education_and_admin_staff(): void
    {
        Storage::fake('public');
        $visit = $this->visit();
        $url = '/portal/education/rsvp-visits/'.$visit->id.'/logo';
        $this->post($url, ['logo' => UploadedFile::fake()->image('school.png')])->assertRedirect('/login');
        $sales = User::factory()->create(['role' => 'sales']);
        $this->actingAs($sales)->post($url, ['logo' => UploadedFile::fake()->image('school.png')])->assertForbidden();
        $this->assertSame('/education-rsvp/assets/brokenshire-logo.png', $visit->fresh()->logo_path);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_invalid_oversized_and_active_image_uploads_leave_the_current_logo_unchanged(): void
    {
        Storage::fake('public');
        $visit = $this->visit();
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $files = [
            UploadedFile::fake()->createWithContent('disguised.png', '%PDF-1.7 not an image'),
            UploadedFile::fake()->createWithContent('active.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->image('large.png')->size(2049),
            UploadedFile::fake()->image('wide.png', 4097, 8),
        ];
        foreach ($files as $file) {
            $this->postJson('/portal/education/rsvp-visits/'.$visit->id.'/logo', ['logo' => $file])->assertUnprocessable()->assertJsonValidationErrors('logo');
            $this->assertSame('/education-rsvp/assets/brokenshire-logo.png', $visit->fresh()->logo_path);
        }
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }
}
