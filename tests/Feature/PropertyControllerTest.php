<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PropertyControllerTest extends TestCase
{
    use RefreshDatabase;

    private function accommodationUser(): User
    {
        return User::factory()->create(['role' => 'accommodation']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Sunnyvale House',
            'location' => 'Auckland, NZ',
            'suburb' => 'Glenfield',
            'rental_mode' => 'per_room',
            'room_type' => 'ensuite',
            'has_wardrobe' => true,
            'bed_type' => 'double',
            'bathroom_type' => 'private',
            'includes' => 'Shared kitchen with fridge, microwave, washing machine.',
            'rent_single' => 250,
            'rent_couple' => 300,
            'bills_excluded' => true,
            'description' => 'A lovely home.',
            'status' => 'available',
        ], $overrides);
    }

    public function test_all_weekdays_and_fortnightly_can_be_saved_as_payment_schedules(): void
    {
        $this->actingAs($this->accommodationUser());
        foreach (['Every Monday', 'Every Tuesday', 'Every Wednesday', 'Every Thursday', 'Every Friday', 'Every Saturday', 'Every Sunday', 'Fortnightly', 'Fortnight Friday', 'Auto Monday', 'Manual'] as $schedule) {
            $this->post('/portal/accommodation/properties', $this->payload([
                'rental_mode' => 'per_room', 'pm_payment_schedule' => $schedule,
            ]))->assertSessionHasNoErrors()->assertRedirect();
            $this->assertDatabaseHas('accommodation_properties', ['pm_payment_schedule' => $schedule]);
        }
    }

    public function test_properties_index_requires_portal_access(): void
    {
        $this->get('/portal/accommodation/properties')->assertRedirect('/login');

        $sales = User::factory()->create(['role' => 'sales']);
        $this->actingAs($sales)->get('/portal/accommodation/properties')->assertForbidden();
    }

    public function test_accommodation_user_can_view_index(): void
    {
        $this->actingAs($this->accommodationUser())
            ->get('/portal/accommodation/properties')
            ->assertOk();
    }

    public function test_store_creates_property_with_images(): void
    {
        Storage::fake('public');

        $payload = $this->payload([
            'images' => [
                UploadedFile::fake()->create('a.jpg', 100, 'image/jpeg'),
                UploadedFile::fake()->create('b.jpg', 100, 'image/jpeg'),
            ],
        ]);

        $this->actingAs($this->accommodationUser())
            ->post('/portal/accommodation/properties', $payload)
            ->assertRedirect('/portal/accommodation/properties');

        $this->assertDatabaseHas('accommodation_properties', ['name' => 'Sunnyvale House']);
        $property = Property::first();
        $this->assertCount(2, $property->images);
        Storage::disk('public')->assertExists($property->images->first()->path);
    }

    public function test_update_edits_fields_and_appends_images(): void
    {
        Storage::fake('public');
        $property = Property::create($this->payload());

        $this->actingAs($this->accommodationUser())
            ->post("/portal/accommodation/properties/{$property->id}", $this->payload([
                '_method' => 'PUT',
                'name' => 'Renamed House',
                'images' => [UploadedFile::fake()->create('c.jpg', 100, 'image/jpeg')],
            ]))
            ->assertRedirect("/portal/accommodation/properties/{$property->id}");

        $this->assertDatabaseHas('accommodation_properties', [
            'id' => $property->id, 'name' => 'Renamed House',
        ]);
        $this->assertCount(1, $property->fresh()->images);
    }

    public function test_destroy_image_removes_single_image(): void
    {
        Storage::fake('public');
        $property = Property::create($this->payload());
        $image = $property->images()->create([
            'path' => 'accommodation/properties/x.jpg', 'sort_order' => 1,
        ]);
        Storage::disk('public')->put('accommodation/properties/x.jpg', 'data');

        $this->actingAs($this->accommodationUser())
            ->delete("/portal/accommodation/properties/{$property->id}/images/{$image->id}")
            ->assertRedirect();

        $this->assertDatabaseMissing('accommodation_property_images', ['id' => $image->id]);
        Storage::disk('public')->assertMissing('accommodation/properties/x.jpg');
    }

    public function test_destroy_deletes_property_and_images(): void
    {
        Storage::fake('public');
        $property = Property::create($this->payload());
        $image = $property->images()->create([
            'path' => 'accommodation/properties/y.jpg', 'sort_order' => 1,
        ]);
        Storage::disk('public')->put('accommodation/properties/y.jpg', 'data');

        $this->actingAs($this->accommodationUser())
            ->delete("/portal/accommodation/properties/{$property->id}")
            ->assertRedirect('/portal/accommodation/properties');

        $this->assertDatabaseMissing('accommodation_properties', ['id' => $property->id]);
        $this->assertDatabaseMissing('accommodation_property_images', ['id' => $image->id]);
        Storage::disk('public')->assertMissing('accommodation/properties/y.jpg');
    }
}
