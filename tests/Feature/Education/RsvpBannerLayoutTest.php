<?php

namespace Tests\Feature\Education;

use App\Models\User;
use App\Services\EducationRsvpContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

class RsvpBannerLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_banner_can_be_published_full_width_and_restored_to_split(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']));
        $this->get('/portal/education/rsvps')->assertOk()->assertSee('Full-width banner');
        $payload = Arr::only(app(EducationRsvpContent::class)->defaults()['hero'], ['heading', 'description', 'image_alt']);

        $this->get('/education/rsvp')->assertOk()->assertSee('class="destination"', false);
        $this->post('/portal/education/rsvps/content/hero', $payload + ['layout' => 'full'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/education/rsvp')->assertOk()->assertSee('class="story story-full"', false)
            ->assertDontSee('class="destination"', false);

        // Older editor tabs must preserve an existing layout when they save text.
        $this->post('/portal/education/rsvps/content/hero', $payload)->assertSessionHasNoErrors();
        $this->assertSame('full', app(EducationRsvpContent::class)->get()['hero']['layout']);
        $this->post('/portal/education/rsvps/content/hero', $payload + ['layout' => 'split'])
            ->assertSessionHasNoErrors();
        $this->get('/education/rsvp')->assertOk()->assertDontSee('class="story story-full"', false)
            ->assertSee('class="destination"', false);
    }

    public function test_banner_layout_rejects_unknown_values(): void
    {
        $payload = Arr::only(app(EducationRsvpContent::class)->defaults()['hero'], ['heading', 'description', 'image_alt']);
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->post('/portal/education/rsvps/content/hero', $payload + ['layout' => 'unsupported'])
            ->assertSessionHasErrors('layout');
        $this->assertSame('split', app(EducationRsvpContent::class)->get()['hero']['layout']);
    }
}
