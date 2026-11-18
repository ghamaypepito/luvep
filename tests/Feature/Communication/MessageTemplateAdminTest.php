<?php

namespace Tests\Feature\Communication;

use App\Mail\TemplatedMessage;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MessageTemplateAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'email' => 'admin@test.co']);
    }

    private function template(array $attrs = []): MessageTemplate
    {
        return MessageTemplate::create(array_merge([
            'key' => 'welcome_x', 'name' => 'Welcome X', 'channels' => ['email'],
            'email_subject' => 'Hi {{first_name}}', 'email_body' => 'Hello {{first_name}}', 'is_active' => true,
        ], $attrs));
    }

    public function test_admin_can_list_templates(): void
    {
        $existingCount = MessageTemplate::count();
        $template = $this->template();

        $this->actingAs($this->admin())
            ->get('/admin/message-templates')
            ->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('admin/MessageTemplates')
                ->has('templates', $existingCount + 1)
                ->where('templates', fn ($templates) => collect($templates)->pluck('id')->contains($template->id)));
    }

    public function test_admin_can_create_template(): void
    {
        $this->actingAs($this->admin())->post('/admin/message-templates', [
            'key' => 'new_one', 'name' => 'New One', 'channels' => ['email', 'sms'],
            'email_subject' => 'Sub', 'email_body' => 'Body', 'sms_body' => 'Sms', 'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('message_templates', ['key' => 'new_one', 'name' => 'New One']);
    }

    public function test_non_admin_gets_403(): void
    {
        $sales = User::factory()->create(['role' => 'sales']);
        $this->actingAs($sales)->get('/admin/message-templates')->assertForbidden();
        $this->actingAs($sales)->post('/admin/message-templates', ['key' => 'x', 'name' => 'x'])->assertForbidden();
    }

    public function test_editing_persists(): void
    {
        $t = $this->template();

        $this->actingAs($this->admin())->put("/admin/message-templates/{$t->id}", [
            'name' => 'Renamed', 'channels' => ['email'], 'email_subject' => 'New', 'email_body' => 'B', 'is_active' => false,
        ])->assertRedirect();

        $t->refresh();
        $this->assertSame('Renamed', $t->name);
        $this->assertFalse($t->is_active);
    }

    public function test_soft_delete_works(): void
    {
        $t = $this->template();
        $this->actingAs($this->admin())->delete("/admin/message-templates/{$t->id}")->assertRedirect();
        $this->assertSoftDeleted('message_templates', ['id' => $t->id]);
    }

    public function test_unique_key_enforced(): void
    {
        $this->template(['key' => 'dupe']);
        $this->actingAs($this->admin())->post('/admin/message-templates', [
            'key' => 'dupe', 'name' => 'Other', 'channels' => ['email'],
        ])->assertSessionHasErrors('key');
    }

    public function test_send_test_uses_current_user(): void
    {
        Mail::fake();
        $admin = $this->admin();
        $t = $this->template(['is_active' => true]);

        $this->actingAs($admin)->post("/admin/message-templates/{$t->id}/test")->assertRedirect();

        Mail::assertQueued(TemplatedMessage::class, fn ($m) => $m->hasTo('admin@test.co'));
        $this->assertDatabaseHas('message_logs', ['template_key' => 'welcome_x', 'recipient_address' => 'admin@test.co']);
    }
}
