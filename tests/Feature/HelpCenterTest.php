<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HelpCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_must_sign_in(): void
    {
        $this->get('/help-center')->assertRedirect('/login');
    }

    public static function internalRoles(): array
    {
        return array_map(fn ($role) => [$role], ['super_admin', 'admin', 'sales', 'education', 'english', 'immigration', 'immigration_manager', 'immigration_adviser', 'accommodation', 'finance']);
    }

    #[DataProvider('internalRoles')]
    public function test_internal_staff_receive_their_permitted_guide_catalogue(string $role): void
    {
        $admin = in_array($role, ['super_admin', 'admin'], true);
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get('/help-center')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('help/Index', false)
            ->where('auth.can_view_help', true)
            ->has('guides', $admin ? 15 : 12)
            ->where('guides', function ($guides) use ($admin) {
                $ids = collect($guides)->pluck('id')->all();

                return in_array('03', $ids, true) && in_array('05', $ids, true)
                    && in_array('11', $ids, true) === $admin
                    && in_array('12', $ids, true) === $admin
                    && in_array('14', $ids, true) === $admin;
            })
            ->where('departments', fn ($departments) => collect($departments)->contains('id', 'technical') === $admin));
    }

    public static function externalRoles(): array
    {
        return [['lead'], ['revoked_lead'], ['agent'], ['sub_agent'], ['unknown']];
    }

    #[DataProvider('externalRoles')]
    public function test_external_roles_cannot_read_internal_help_even_with_an_extra_portal(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => ['education']]))
            ->get('/help-center')->assertForbidden();
    }

    public function test_deactivated_staff_cannot_read_help(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => false]))
            ->get('/help-center')->assertRedirect('/login');
    }

    public function test_guide_links_and_department_references_form_a_usable_catalogue(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/help-center')->assertOk();
        $guides = $response->viewData('page')['props']['guides'];
        $departments = collect($response->viewData('page')['props']['departments'])->pluck('id');
        $this->assertCount(15, array_unique(array_column($guides, 'id')));
        foreach ($guides as $guide) {
            $this->assertMatchesRegularExpression('~^https://docs\.google\.com/document/d/[A-Za-z0-9_-]+/edit$~', $guide['url']);
            $this->assertNotEmpty($guide['topics']);
            foreach ($guide['departments'] as $department) {
                $this->assertContains($department, $departments);
            }
        }
        $this->assertSame('https://docs.google.com/document/d/16oo2OY_TZ6yyZ2Rkqi9h-v_WyJGYhTwDLbm0OQmWiGg/edit', collect($guides)->firstWhere('id', '03')['url']);
    }
}
