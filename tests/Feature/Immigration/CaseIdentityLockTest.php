<?php

namespace Tests\Feature\Immigration;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CaseIdentityLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_case_name_is_locked_when_viewing_as_immigration_staff(): void
    {
        $case = Lead::create([
            'first_name' => 'Original', 'last_name' => 'Applicant', 'email' => 'case@example.test',
            'is_immigration_case' => true,
        ]);
        $admin = User::factory()->create(['role' => 'super_admin']);
        $manager = User::factory()->create(['role' => 'immigration_manager']);
        $url = "/portal/immigration/cases/{$case->id}";

        $this->actingAs($admin)->postJson("/admin/user-view/{$manager->id}")->assertOk();
        $token = session('user_view.token');
        $this->get($url.'/profile')->assertInertia(fn (Assert $page) => $page->where('canCorrectCaseNames', false));
        $this->withHeader('X-Luvep-User-View', $token)->postJson($url.'/personal', [
            'first_name' => 'Changed', 'email' => $case->email,
        ])->assertUnprocessable()->assertJsonValidationErrors('first_name');
        $this->assertSame('Original', $case->fresh()->first_name);

        $this->postJson('/admin/user-view/exit')->assertOk();
        $this->withoutHeader('X-Luvep-User-View')->actingAs(User::factory()->create(['role' => 'admin']))
            ->post($url.'/personal', ['first_name' => 'Corrected', 'email' => $case->email])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Corrected', $case->fresh()->first_name);
    }

    public function test_public_draft_does_not_rename_an_existing_case(): void
    {
        $case = Lead::create([
            'lead_id' => 'IC-TEST-NAME', 'first_name' => 'Original', 'last_name' => 'Applicant',
            'email' => 'case@example.test', 'education_stage' => 'Endorsed to Immigration',
        ]);

        $this->postJson('/free-assessment/draft', [
            'first_name' => 'Different', 'last_name' => 'Name', 'email' => $case->email, 'phone' => '0210000000',
        ])->assertOk()->assertJsonPath('draft_saved', true);

        $this->assertDatabaseHas('leads', [
            'id' => $case->id, 'lead_id' => 'IC-TEST-NAME', 'first_name' => 'Original',
            'last_name' => 'Applicant', 'phone' => '0210000000',
        ]);
    }
}
