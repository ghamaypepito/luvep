<?php

namespace Tests\Feature;

use App\Models\EducationVisit;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EducationRsvpContentTest extends TestCase
{
    use RefreshDatabase;

    private function hero(array $extra = []): array
    {
        return array_merge(['heading' => 'Meet your next chapter', 'description' => 'Find your school and meet our Education team.', 'image_alt' => 'Students discussing their plans'], $extra);
    }

    public function test_banner_layout_defaults_to_split_and_is_available_to_staff(): void
    {
        $this->get('/education/rsvp')->assertOk()->assertSee('data-banner-layout="split"', false);
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->get('/portal/education/rsvps')->assertOk()->assertSee('Banner layout')->assertSee('Full banner');
    }

    public function test_banner_layout_can_switch_without_losing_the_saved_side_panel(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $this->postJson('/portal/education/rsvps/content/destination', ['caption' => 'Saved waterfront caption', 'image_alt' => 'Saved waterfront photo'])->assertOk();
        $this->postJson('/portal/education/rsvps/content/hero', $this->hero(['banner_layout' => 'full']))->assertOk();
        $this->get('/education/rsvp')->assertOk()->assertSee('data-banner-layout="full"', false)->assertSee('story--full')->assertDontSee('class="destination"', false);
        $this->assertSame('Saved waterfront caption', Setting::get('education_rsvp_content_destination')['caption']);
        $this->postJson('/portal/education/rsvps/content/hero', $this->hero(['banner_layout' => 'split']))->assertOk();
        $this->get('/education/rsvp')->assertSee('data-banner-layout="split"', false)->assertSee('class="destination"', false)->assertSee('Saved waterfront caption');
        $this->assertDatabaseCount('education_outreach', 0);
    }

    public function test_banner_layout_survives_older_forms_and_rejects_unknown_values(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $this->postJson('/portal/education/rsvps/content/hero', $this->hero(['banner_layout' => 'full']))->assertOk();
        $this->postJson('/portal/education/rsvps/content/hero', $this->hero(['heading' => 'Text only update']))->assertOk();
        $this->assertSame('full', Setting::get('education_rsvp_content_hero')['banner_layout']);
        $this->postJson('/portal/education/rsvps/content/hero', $this->hero(['banner_layout' => 'unknown']))->assertUnprocessable()->assertJsonValidationErrors('banner_layout');
        $this->assertSame('full', Setting::get('education_rsvp_content_hero')['banner_layout']);
    }

    public function test_invalid_stored_banner_layout_falls_back_to_split(): void
    {
        Setting::set('education_rsvp_content_hero', ['banner_layout' => 'unknown'], 'json');
        $this->get('/education/rsvp')->assertOk()->assertSee('data-banner-layout="split"', false);
    }

    public function test_staff_publish_escaped_shared_content_without_changing_schedules_or_outreach(): void
    {
        $v = EducationVisit::create(['slug' => 'content-school', 'school' => 'Content College', 'kind' => 'school', 'activity' => 'Education event', 'schedule_version' => 4]);
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $this->postJson('/portal/education/rsvps/content/hero', $this->hero(['heading' => '<script>unsafe()</script>']))->assertOk();
        $this->get('/education/rsvp')->assertOk()->assertSee('&lt;script&gt;unsafe()&lt;/script&gt;', false)->assertDontSee('<script>unsafe()</script>', false)->assertSee('Find your school and meet our Education team.');
        $this->assertSame(4, $v->fresh()->schedule_version);
        $this->assertDatabaseCount('education_outreach', 0);
        $this->assertDatabaseHas('activity_logs', ['action' => 'education.rsvp.content_updated']);
    }

    public function test_replacing_one_photo_preserves_it_on_later_text_save_and_keeps_other_sections(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $this->postJson('/portal/education/rsvps/content/hero', $this->hero(['image' => UploadedFile::fake()->image('campus.jpg', 1200, 700)]))->assertOk();
        $photos = Storage::disk('public')->allFiles('education/rsvp-content/hero');
        $this->assertCount(1, $photos);
        $this->postJson('/portal/education/rsvps/content/destination', ['caption' => 'A new chapter awaits.', 'image_alt' => 'Wellington waterfront'])->assertOk();
        $this->postJson('/portal/education/rsvps/content/hero', $this->hero(['heading' => 'Updated headline']))->assertOk();
        $this->get('/education/rsvp')->assertSee('Updated headline')->assertSee(basename($photos[0]))->assertSee('A new chapter awaits.')->assertSee('/education-rsvp/assets/conversation.webp', false);
        $this->assertCount(1, Storage::disk('public')->allFiles('education/rsvp-content/hero'));
    }

    public function test_invalid_images_and_unauthorized_edits_cannot_publish_content(): void
    {
        Storage::fake('public');
        $this->postJson('/portal/education/rsvps/content/hero', $this->hero())->assertUnauthorized();
        $this->actingAs(User::factory()->create(['role' => 'sales']))->postJson('/portal/education/rsvps/content/hero', $this->hero())->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $this->postJson('/portal/education/rsvps/content/unknown', $this->hero())->assertNotFound();
        foreach ([UploadedFile::fake()->createWithContent('bad.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>bad()</script></svg>'), UploadedFile::fake()->image('big.jpg')->size(5121)] as $image) {
            $this->postJson('/portal/education/rsvps/content/hero', $this->hero(['image' => $image]))->assertUnprocessable()->assertJsonValidationErrors('image');
        }
        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->get('/education/rsvp')->assertSee('Choose your event.');
    }
}
