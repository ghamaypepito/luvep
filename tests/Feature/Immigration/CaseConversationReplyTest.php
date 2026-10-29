<?php

namespace Tests\Feature\Immigration;

use App\Mail\TemplatedMessage;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CaseConversationReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_immigration_staff_can_reply_through_the_shared_conversation_endpoint(): void
    {
        Mail::fake();
        $case = Lead::create(['first_name' => 'Case', 'last_name' => 'Example', 'email' => 'case@example.test', 'is_immigration_case' => true]);
        foreach (['immigration', 'immigration_manager', 'immigration_adviser'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->postJson("/admin/leads/{$case->id}/communications", ['subject' => 'Re: Visa question', 'body' => 'Synthetic reply'])
                ->assertOk()->assertJsonPath('ok', true);
        }
        Mail::assertQueued(TemplatedMessage::class, 3);
        Mail::assertQueued(TemplatedMessage::class, fn ($mail) => $mail->hasTo('case@example.test'));
        $this->assertDatabaseHas('message_logs', ['recipient_id' => $case->id, 'subject' => 'Re: Visa question']);
    }

    public function test_external_and_other_department_users_cannot_reply_to_the_case(): void
    {
        Mail::fake();
        $case = Lead::create(['first_name' => 'Private', 'last_name' => 'Case', 'email' => 'private@example.test', 'is_immigration_case' => true]);
        foreach (['lead', 'agent', 'education', 'sales'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role, 'portal_access' => in_array($role, ['lead', 'agent'], true) ? ['immigration'] : []]))
                ->postJson("/admin/leads/{$case->id}/communications", ['subject' => 'Attempt', 'body' => 'Not allowed'])
                ->assertForbidden();
        }
        Mail::assertNothingOutgoing();
        $this->assertDatabaseCount('message_logs', 0);
    }
}
