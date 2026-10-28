<?php

namespace Tests\Feature\Communication;

use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DepartmentTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function tmpl(array $attrs = []): MessageTemplate
    {
        return MessageTemplate::create(array_merge([
            'key' => 'application_status_update', 'name' => 'Status', 'channels' => ['email'],
            'email_subject' => 'Hi {{first_name}}', 'email_body' => 'Status: {{status}}', 'is_active' => true,
        ], $attrs));
    }

    public function test_resolve_prefers_department_over_global(): void
    {
        $this->tmpl(['department' => '', 'name' => 'Global']);
        $this->tmpl(['department' => 'sales', 'name' => 'Sales']);

        $this->assertSame('Sales', MessageTemplate::resolve('application_status_update', 'sales')->name);
        // A department with no own copy falls back to the global one.
        $this->assertSame('Global', MessageTemplate::resolve('application_status_update', 'education')->name);
        // No department context → global only.
        $this->assertSame('Global', MessageTemplate::resolve('application_status_update', null)->name);
    }

    public function test_same_key_allowed_across_departments(): void
    {
        $this->tmpl(['department' => 'sales']);
        $this->tmpl(['department' => 'education']);   // must not collide

        $this->assertSame(2, MessageTemplate::where('key', 'application_status_update')->count());
    }

    public function test_portal_lists_the_shared_library_across_departments(): void
    {
        $existingCount = MessageTemplate::count();
        $this->tmpl(['department' => 'sales', 'name' => 'Sales One']);
        $this->tmpl(['department' => 'education', 'name' => 'Edu One']);
        $this->tmpl(['department' => '', 'name' => 'Global One']);
        $deleted = $this->tmpl(['key' => 'deleted_template', 'name' => 'Deleted']);
        $deleted->delete();

        $this->actingAs(User::factory()->create(['role' => 'sales']))
            ->get('/portal/sales/email-templates')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('portal/sales/EmailTemplates')
                ->has('templates', $existingCount + 3)
                ->where('templates', fn ($templates) => collect($templates)->pluck('name')->contains('Sales One')
                    && collect($templates)->pluck('name')->contains('Edu One')
                    && collect($templates)->pluck('name')->contains('Global One')
                    && ! collect($templates)->pluck('name')->contains('Deleted')));
    }

    public function test_admin_browsing_a_portal_sees_the_shared_library_in_the_portal_layout(): void
    {
        $existingCount = MessageTemplate::count();
        $this->tmpl(['department' => 'sales', 'name' => 'Sales One']);
        $this->tmpl(['department' => 'education', 'name' => 'Edu One']);

        // The shared library keeps the acting portal's layout and links.
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get('/portal/sales/email-templates')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('portal/sales/EmailTemplates')
                ->has('templates', $existingCount + 2)
                ->where('basePath', '/portal/sales/email-templates')
                ->where('templates', fn ($templates) => collect($templates)->pluck('name')->contains('Sales One')
                    && collect($templates)->pluck('name')->contains('Edu One')));
    }

    public function test_portal_create_honours_the_selected_department(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'education']))
            ->post('/portal/education/email-templates', [
                'key' => 'edu_note', 'name' => 'Edu Note', 'channels' => ['email'],
                'email_subject' => 'S', 'email_body' => 'B', 'department' => 'sales', // selected library container
            ])->assertRedirect();

        $this->assertDatabaseHas('message_templates', ['key' => 'edu_note', 'department' => 'sales']);
        $this->assertDatabaseMissing('message_templates', ['key' => 'edu_note', 'department' => 'education']);
    }

    public function test_staff_can_view_and_edit_another_departments_and_shared_templates(): void
    {
        $sales = $this->tmpl(['department' => 'sales']);
        $global = $this->tmpl(['department' => '', 'key' => 'doc_x']);

        $edu = User::factory()->create(['role' => 'education']);
        foreach ([$sales, $global] as $template) {
            $this->actingAs($edu)->get("/portal/education/email-templates/{$template->id}")
                ->assertOk()
                ->assertInertia(fn (Assert $p) => $p
                    ->component('portal/education/EmailTemplateEditor')
                    ->where('template.id', $template->id));

            $this->actingAs($edu)->put("/portal/education/email-templates/{$template->id}", [
                'name' => 'Edited '.$template->id, 'channels' => ['email'], 'email_body' => 'Updated body',
                'department' => $template->department,
            ])->assertRedirect()->assertSessionHasNoErrors();

            $this->assertDatabaseHas('message_templates', [
                'id' => $template->id, 'department' => $template->department,
                'name' => 'Edited '.$template->id, 'email_body' => 'Updated body',
            ]);
        }
    }

    public function test_external_client_cannot_access_the_shared_template_library(): void
    {
        $template = $this->tmpl(['department' => 'sales']);
        $this->actingAs(User::factory()->create(['role' => 'lead']));

        $this->get('/portal/education/email-templates')->assertForbidden();
        $this->get("/portal/education/email-templates/{$template->id}")->assertForbidden();
        $this->post('/portal/education/email-templates', [
            'key' => 'unauthorized', 'name' => 'Unauthorized', 'channels' => ['email'],
        ])->assertForbidden();
        $this->put("/portal/education/email-templates/{$template->id}", [
            'name' => 'Unauthorized edit', 'channels' => ['email'],
        ])->assertForbidden();

        $this->assertDatabaseMissing('message_templates', ['key' => 'unauthorized']);
        $this->assertSame('Status', $template->fresh()->name);
    }

    public function test_immigration_subrole_resolves_to_immigration_department(): void
    {
        $existingCount = MessageTemplate::count();
        $this->tmpl(['department' => 'immigration', 'name' => 'Imm One']);

        $this->actingAs(User::factory()->create(['role' => 'immigration_manager']))
            ->get('/portal/immigration/email-templates')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p
                ->component('portal/immigration/EmailTemplates')
                ->has('templates', $existingCount + 1)
                ->where('basePath', '/portal/immigration/email-templates')
                ->where('templates', fn ($templates) => collect($templates)->pluck('name')->contains('Imm One')));
    }
}
