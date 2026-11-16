<?php

namespace App\Jobs;

use App\Mail\TemplatedMessage;
use App\Models\EducationSchoolMailRecipient;
use App\Models\MessageLog;
use App\Services\EducationSchoolMailAudience;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

class SendEducationSchoolMail implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $recipientId) {}

    /** Dispatch errors leave the durable pending recipient recoverable. */
    public static function enqueue(int $id): bool
    {
        try {
            \Illuminate\Support\Facades\Bus::dispatch(new self($id));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public function handle(EducationSchoolMailAudience $audience): void
    {
        Cache::lock('education-school-mail:'.$this->recipientId, 75)->get(function () use ($audience) {
            $r = EducationSchoolMailRecipient::with('mailing.creator')->find($this->recipientId);
            if (! $r || $r->status !== 'pending') {
                return;
            }
            $m = $r->mailing;
            $u = $m->creator;
            if (! $u || ! $u->is_active || ! $u->canAccessPortal('education') || ! ($u->role === 'education' || $u->isAdmin())) {
                $r->update(['status' => 'skipped', 'detail' => 'The sender no longer has Education mailing access.']);

                return;
            }
            $current = $audience->rows($m->filters, $r->email)->firstWhere('email', $r->email);
            if (! $current || ! $current['eligible'] || $current['name'] !== $r->name || $current['source_refs'] != $r->source_refs || $current['context'] != $r->context_snapshot) {
                $r->update(['status' => 'skipped', 'detail' => 'Registration, address, visit details, attendance or email permission changed.']);

                return;
            }
            $t = $m->template_snapshot;
            $subject = $audience->render($t['subject'], $current['context'], false);
            $body = $audience->render($t['body'], $current['context'], true);
            // Durable claim before I/O. An uncertain delivery is never retried automatically.
            $r->update(['status' => 'sending']);
            $log = MessageLog::create(['source' => 'education-school-mail', 'channel' => 'email', 'recipient_type' => 'raw',
                'recipient_id' => null, 'recipient_address' => $r->email, 'subject' => $subject, 'body' => $body, 'status' => 'queued', 'triggered_by_user_id' => $u->id]);
            $r->update(['message_log_id' => $log->id]);
            try {
                Mail::sendNow((new TemplatedMessage($subject, $body, [], $t['banner'], $t['footer'], $log->id, $t['from_email'], $t['from_name'],
                    replyToEmail: $t['reply_to_email'], replyToName: $t['reply_to_name']))->to($r->email));
                $log->update(['status' => 'sent', 'sent_at' => now()]);
                $r->update(['status' => 'sent', 'detail' => 'Accepted by the configured mail transport. Inbox delivery is not guaranteed.']);
            } catch (\Throwable $e) {
                report($e);
                $log->update(['status' => 'failed', 'failed_at' => now(), 'error_message' => 'Transport failed or delivery is uncertain. Review before resending.']);
                $r->update(['status' => 'failed', 'detail' => 'Transport failed or delivery is uncertain. Review before resending.']);
            }
        });
    }

    public function failed(?\Throwable $e): void
    {
        EducationSchoolMailRecipient::whereKey($this->recipientId)->whereIn('status', ['pending', 'sending'])
            ->update(['status' => 'failed', 'detail' => 'Worker failed or delivery is uncertain. Review before resending.']);
    }
}
