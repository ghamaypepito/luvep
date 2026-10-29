<?php

namespace Tests\Feature\Tickets;

use App\Models\SystemTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TicketAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['title' => 'Screen recording of issue', 'description' => 'Synthetic reproduction', 'category' => 'bug', 'priority' => 'normal'], $extra);
    }

    private function ticket(User $user, array $extra = []): SystemTicket
    {
        return SystemTicket::create(array_merge($this->payload(), ['submitted_by' => $user->id, 'department' => $user->role, 'status' => 'open'], $extra));
    }

    public function test_staff_can_submit_multiple_mixed_attachments_with_legacy_screenshots(): void
    {
        $owner = User::factory()->create(['role' => 'immigration_manager']);
        $this->actingAs($owner)->post('/tickets', $this->payload([
            'images' => [UploadedFile::fake()->image('legacy.png')],
            'attachments' => [UploadedFile::fake()->image('screen.png'), UploadedFile::fake()->create('recording.mp4', 20, 'video/mp4'), UploadedFile::fake()->createWithContent('notes.txt', 'Synthetic details')],
        ]))->assertRedirect()->assertSessionHasNoErrors();
        $ticket = SystemTicket::firstOrFail();
        $this->assertCount(1, $ticket->images);
        $this->assertCount(3, $ticket->attachments);
        $this->assertSame(['screen.png', 'recording.mp4', 'notes.txt'], array_column($ticket->attachments, 'name'));
        foreach ($ticket->attachments as $attachment) {
            Storage::disk('local')->assertExists($attachment['path']);
            Storage::disk('public')->assertMissing($attachment['path']);
        }
        $this->get('/portal/tickets')->assertInertia(fn (Assert $page) => $page
            ->has('tickets.data.0.attachments', 3)
            ->where('tickets.data.0.attachments.1.mime', 'video/mp4')
            ->where('tickets.data.0.attachments.1.url', url("/system-tickets/{$ticket->id}/attachments/1?inline=1"))
            ->missing('tickets.data.0.attachments.1.path')
            ->has('tickets.data.0.images', 1));
        $this->get("/system-tickets/{$ticket->id}/images/0")->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/admin/system-tickets')
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data.0.attachments', 3)->missing('tickets.data.0.attachments.0.path'));
    }

    public function test_attachment_only_reply_is_saved_and_serialized_without_private_paths(): void
    {
        $owner = User::factory()->create(['role' => 'education']);
        $ticket = $this->ticket($owner);
        $response = $this->actingAs($owner)->post("/system-tickets/{$ticket->id}/messages", [
            'attachments' => [UploadedFile::fake()->create('recording.webm', 25, 'video/webm'), UploadedFile::fake()->image('screen.png')],
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('message.body', '')->assertJsonCount(2, 'message.attachments')
            ->assertJsonMissingPath('message.attachments.0.path');
        $message = $ticket->messages()->firstOrFail();
        $this->assertCount(2, $message->attachments);
        $this->assertSame($owner->id, $message->user_id);
        Storage::disk('local')->assertExists($message->attachments[0]['path']);
        $this->getJson("/system-tickets/{$ticket->id}/conversation")->assertOk()
            ->assertJsonPath('messages.data.0.attachments.0.name', 'recording.webm')
            ->assertJsonMissingPath('messages.data.0.attachments.0.path');
        $this->get($response->json('message.attachments.0.download_url'))->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=recording.webm');
    }

    public function test_support_can_add_files_to_a_reply_and_the_owner_can_download_them(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $ticket = $this->ticket($owner);
        $support = User::factory()->create(['role' => 'super_admin']);
        $response = $this->actingAs($support)->post("/system-tickets/{$ticket->id}/messages", [
            'body' => 'Instructions attached', 'attachments' => [UploadedFile::fake()->createWithContent('instructions.txt', 'Synthetic instructions')],
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('message.is_support', true);
        $this->actingAs($owner)->get($response->json('message.attachments.0.download_url'))->assertOk();
        $this->assertDatabaseHas('system_ticket_events', ['ticket_id' => $ticket->id, 'kind' => 'first_response']);
    }

    public function test_inline_media_supports_byte_ranges_but_documents_always_download(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $this->actingAs($owner)->post('/tickets', $this->payload(['attachments' => [UploadedFile::fake()->image('screen.png'), UploadedFile::fake()->createWithContent('notes.txt', 'Private synthetic text')]]));
        $ticket = SystemTicket::firstOrFail();
        $this->get("/system-tickets/{$ticket->id}/attachments/0?inline=1", ['Range' => 'bytes=0-7'])
            ->assertStatus(206)->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get("/system-tickets/{$ticket->id}/attachments/1?inline=1")->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=notes.txt');
    }

    public function test_only_active_ticket_owner_and_support_can_access_files_even_with_range_requests(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $this->actingAs($owner)->post('/tickets', $this->payload(['attachments' => [UploadedFile::fake()->image('private.png')]]));
        $ticket = SystemTicket::firstOrFail();
        $reply = $this->post("/system-tickets/{$ticket->id}/messages", ['body' => 'More details', 'attachments' => [UploadedFile::fake()->image('reply.png')]], ['Accept' => 'application/json'])->assertCreated();
        $urls = ["/system-tickets/{$ticket->id}/attachments/0?inline=1", $reply->json('message.attachments.0.url')];
        foreach (['sales', 'education', 'lead', 'revoked_lead'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            foreach ($urls as $url) {
                $this->actingAs($user)->get($url, ['Range' => 'bytes=0-7'])->assertForbidden();
            }
        }
        foreach (['admin', 'super_admin'] as $role) {
            foreach ($urls as $url) {
                $this->actingAs(User::factory()->create(['role' => $role]))->get($url)->assertOk();
            }
        }
        $owner->update(['is_active' => false]);
        $this->actingAs($owner)->getJson($urls[0])->assertForbidden();
    }

    public function test_message_attachment_must_belong_to_the_ticket_in_the_url(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $first = $this->ticket($owner);
        $other = $this->ticket($owner);
        $this->actingAs($owner)->post("/system-tickets/{$first->id}/messages", ['body' => 'Private', 'attachments' => [UploadedFile::fake()->image('private.png')]], ['Accept' => 'application/json'])->assertCreated();
        $message = $first->messages()->firstOrFail();
        $this->get("/system-tickets/{$other->id}/messages/{$message->id}/attachments/0")->assertNotFound();
        $this->get("/system-tickets/{$first->id}/messages/{$message->id}/attachments/9")->assertNotFound();
    }

    public static function invalidBatches(): array
    {
        return ['unsupported executable' => ['type'], 'content disguised as video' => ['spoof'], 'over size limit' => ['size'], 'over file count' => ['count']];
    }

    #[DataProvider('invalidBatches')]
    public function test_invalid_file_batches_create_no_ticket_message_or_files(string $failure): void
    {
        $spoof = tmpfile();
        fwrite($spoof, '<?php echo "not a video"; ?>');
        $files = match ($failure) {
            'type' => [UploadedFile::fake()->createWithContent('script.html', '<html>Untrusted page</html>')],
            'spoof' => [new UploadedFile(stream_get_meta_data($spoof)['uri'], 'recording.mp4', 'video/mp4', null, true)],
            'size' => [UploadedFile::fake()->create('large.mp4', 51201, 'video/mp4')],
            'count' => array_map(fn ($i) => UploadedFile::fake()->image("screen-$i.png"), range(1, 11)),
        };
        $owner = User::factory()->create(['role' => 'sales']);
        $this->actingAs($owner)->post('/tickets', $this->payload(['attachments' => $files]))->assertSessionHasErrors();
        $this->assertDatabaseCount('system_tickets', 0);
        $ticket = $this->ticket($owner);
        $this->post("/system-tickets/{$ticket->id}/messages", ['body' => 'Invalid batch', 'attachments' => $files], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('system_ticket_messages', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_archived_ticket_rejects_uploads_without_saving_any_files(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $ticket = $this->ticket($owner, ['archived_at' => now()]);
        $this->actingAs($owner)->post("/system-tickets/{$ticket->id}/messages", ['body' => 'Cannot add', 'attachments' => [UploadedFile::fake()->image('screen.png')]], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseCount('system_ticket_messages', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_empty_reply_is_still_invalid_and_legacy_text_replies_work(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $ticket = $this->ticket($owner);
        $this->actingAs($owner)->postJson("/system-tickets/{$ticket->id}/messages", [])->assertUnprocessable();
        $this->postJson("/system-tickets/{$ticket->id}/messages", ['body' => 'Text only'])->assertCreated()->assertJsonPath('message.body', 'Text only');
    }

    public function test_real_mp4_recording_can_be_uploaded_and_seeked(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $video = new UploadedFile(base_path('tests/Fixtures/ticket-recording.mp4'), 'recording.mp4', 'video/mp4', null, true);
        $this->actingAs($owner)->post('/tickets', $this->payload(['attachments' => [$video]]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $ticket = SystemTicket::firstOrFail();
        $this->assertSame('video/mp4', $ticket->attachments[0]['mime']);
        $this->get("/system-tickets/{$ticket->id}/attachments/0?inline=1", ['Range' => 'bytes=0-31'])
            ->assertStatus(206)->assertHeader('Content-Type', 'video/mp4')
            ->assertHeader('Content-Range', 'bytes 0-31/'.filesize(base_path('tests/Fixtures/ticket-recording.mp4')));
    }

    public function test_legacy_and_new_files_share_the_ten_file_batch_limit(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $this->actingAs($owner)->post('/tickets', $this->payload([
            'images' => array_map(fn ($i) => UploadedFile::fake()->image("legacy-$i.png"), range(1, 5)),
            'attachments' => array_map(fn ($i) => UploadedFile::fake()->image("new-$i.png"), range(1, 6)),
        ]))->assertSessionHasErrors('attachments');
        $this->assertDatabaseCount('system_tickets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_failed_ticket_persistence_rolls_back_uploaded_files_and_ticket(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $event = 'eloquent.creating: '.\App\Models\SystemTicketEvent::class;
        app('events')->listen($event, fn () => throw new \RuntimeException('Synthetic event persistence failure'));
        try {
            $this->withoutExceptionHandling()->actingAs($owner)->post('/tickets', $this->payload([
                'images' => [UploadedFile::fake()->image('legacy.png')],
                'attachments' => [UploadedFile::fake()->image('new.png')],
            ]));
            $this->fail('The synthetic persistence error must be propagated.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic event persistence failure', $e->getMessage());
        } finally {
            app('events')->forget($event);
        }
        $this->assertDatabaseCount('system_tickets', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_failed_support_reply_persistence_removes_its_uploaded_files(): void
    {
        $owner = User::factory()->create(['role' => 'sales']);
        $ticket = $this->ticket($owner);
        $support = User::factory()->create(['role' => 'admin']);
        $event = 'eloquent.creating: '.\App\Models\SystemTicketEvent::class;
        app('events')->listen($event, fn () => throw new \RuntimeException('Synthetic response event failure'));
        try {
            $this->withoutExceptionHandling()->actingAs($support)->post("/system-tickets/{$ticket->id}/messages", [
                'body' => 'Evidence attached', 'attachments' => [UploadedFile::fake()->image('response.png')],
            ], ['Accept' => 'application/json']);
            $this->fail('The synthetic persistence error must be propagated.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic response event failure', $e->getMessage());
        } finally {
            app('events')->forget($event);
        }
        $this->assertDatabaseCount('system_ticket_messages', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_partial_file_storage_failure_cleans_only_this_batch(): void
    {
        Storage::disk('local')->put('existing-evidence.txt', 'Must remain');
        $failedFile = new class
        {
            public function store($directory, $disk)
            {
                return false;
            }
        };
        try {
            \App\Support\TicketAttachments::store([UploadedFile::fake()->image('first.png'), $failedFile], 'system-tickets/1/attachments');
            $this->fail('The failed storage operation must be propagated.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Could not store ticket attachment.', $e->getMessage());
        }
        $this->assertSame(['existing-evidence.txt'], Storage::disk('local')->allFiles());
    }
}
