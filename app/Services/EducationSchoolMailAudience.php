<?php

namespace App\Services;

use App\Mail\TemplatedMessage;
use App\Models\EducationEventEmailSubscription;
use App\Models\EducationEventRegistration;
use App\Models\EducationRsvp;
use App\Models\EducationVisit;
use App\Models\EmailReply;
use App\Models\MessageLog;
use App\Models\MessageTemplate;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EducationSchoolMailAudience
{
    public const TOKENS = ['first_name', 'last_name', 'full_name', 'email', 'school', 'event_name', 'event_date', 'event_time', 'event_location'];

    private const SUBJECT_MARKER = '<!-- school-mail-subject -->';

    private const BODY_MARKER = '<!-- school-mail-body -->';

    public function rows(array $filters, ?string $onlyEmail = null): Collection
    {
        $ids = $filters['visit_ids'];
        $visits = EducationVisit::whereIn('id', $ids)->where('status', '!=', 'cancelled')->get()->keyBy('id');
        $rows = collect();
        $suppressed = MessageLog::where('channel', 'email')->where('status', 'bounced')->when($onlyEmail, fn ($q) => $q->whereRaw('LOWER(TRIM(recipient_address)) = ?', [$onlyEmail]))->pluck('recipient_address')
            ->merge(EmailReply::where('direction', 'inbound')->when($onlyEmail, fn ($q) => $q->whereRaw('LOWER(TRIM(from_email)) = ?', [$onlyEmail]))->where(fn ($q) => $q->whereRaw('LOWER(subject) LIKE ?', ['%unsubscribe%'])
                ->orWhereRaw('LOWER(TRIM(body_text)) IN (?, ?, ?, ?)', ['unsubscribe', 'please unsubscribe me', 'stop', 'remove me']))->pluck('from_email'))
            ->map(fn ($email) => strtolower(trim($email)))->unique()->flip();
        foreach (EducationRsvp::whereIn('education_visit_id', $visits->keys())->when($onlyEmail, fn ($q) => $q->whereRaw('LOWER(TRIM(email)) = ?', [$onlyEmail]))->orderBy('id')->get() as $r) {
            if ($r->attendance_status === 'cancelled' || ($filters['audience'] === 'attended' && $r->attendance_status !== 'attended')) {
                continue;
            }
            $rows->push($this->row('rsvp', $r->id, $r->full_name, $r->email, $r->education_visit_id,
                $r->consented_at && $r->verified_at && $r->{$filters['purpose']},
                $r->attendance_status === 'attended' ? ($r->attendance_source === 'automatic' ? 'Recorded attended (automatic)' : 'Recorded attended') : 'Registered', $visits[$r->education_visit_id], $r->created_at));
        }
        // Programme registrations and RSVPs are independent. Never manufacture Lead links.
        // Recorded-attendance mode uses RSVP evidence only.
        if ($filters['audience'] === 'registered') {
            $registrations = EducationEventRegistration::whereIn('education_visit_id', $visits->keys())->whereHas('lead')->with('lead:id,email')
                ->when($onlyEmail, fn ($q) => $q->whereRaw('LOWER(TRIM('.$q->getQuery()->getGrammar()->wrap('contact_details->email').')) = ?', [$onlyEmail]))->orderBy('id')->get();
            $confirmation = $filters['purpose'] === 'email_follow_up' ? 'follow_up_confirmed_at' : 'newsletter_confirmed_at';
            $subscriptions = EducationEventEmailSubscription::whereIntegerInRaw('registration_id', $registrations->modelKeys())->whereNotNull($confirmation)->get()->keyBy('registration_id');
            $verifiedRsvps = $rows->where('eligible', true)->groupBy('email');
            foreach ($registrations as $r) {
                $c = $r->contact_details ?? [];
                $name = trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? ''));
                $email = strtolower(trim($c['email'] ?? ''));
                $nameKey = EducationRsvpWorkflow::nameKey($name);
                $subscription = $subscriptions->get($r->id);
                $confirmed = $subscription && $subscription->lead_id === $r->lead_id && strtolower(trim($subscription->email)) === $email;
                $verified = $r->{$filters['purpose']} && $verifiedRsvps->get($email)?->contains(fn ($rsvp) => $rsvp['ref']['visit_id'] === $r->education_visit_id && EducationRsvpWorkflow::nameKey($rsvp['name']) === $nameKey);
                $rows->push($this->row('registration', $r->id, $name, $email, $r->education_visit_id,
                    $r->consented_at && $email === strtolower(trim((string) $r->lead?->email)) && ($confirmed || $verified), 'Programme registration', $visits[$r->education_visit_id], $r->created_at));
            }
        }

        $usesRsvpList = ! empty($filters['list_id']) || ! empty($filters['folder_id']) || ! empty($filters['rsvp_filters']);
        $matchingRsvpIds = ! $usesRsvpList ? null : app(EducationAttendance::class)->query($filters['rsvp_filters'] ?? [])
            ->when($onlyEmail, fn ($query) => $query->whereRaw('LOWER(TRIM(email)) = ?', [$onlyEmail]))
            ->whereIn('education_visit_id', $visits->keys())->pluck('id')->flip();

        $memberRefs = collect($filters['member_refs'] ?? [])->map(fn ($ref) => $ref['type'].':'.$ref['id'])->flip();

        // Keep all consent evidence, but personalise from a registration matching the requested audience.
        return $rows->groupBy('email')->map(function ($group, $email) use ($suppressed, $matchingRsvpIds, $filters, $memberRefs) {
            $matches = $group->filter(fn ($row) => ($matchingRsvpIds === null || ($row['ref']['type'] === 'rsvp' && $matchingRsvpIds->has($row['ref']['id'])))
                && (! isset($filters['member_refs']) || $memberRefs->has($row['ref']['type'].':'.$row['ref']['id']))
                && (empty($filters['registered_from']) || ($row['registered_on'] && $row['registered_on'] >= $filters['registered_from']))
                && (empty($filters['registered_to']) || ($row['registered_on'] && $row['registered_on'] <= $filters['registered_to'])));
            if ($matches->isEmpty()) {
                return null;
            }
            $first = $matches->first();
            $sameName = $group->map(fn ($r) => EducationRsvpWorkflow::nameKey($r['name']))->unique()->count() === 1;
            $eligible = ! isset($suppressed[$email]) && filter_var($email, FILTER_VALIDATE_EMAIL) && $sameName && $group->every(fn ($r) => $r['eligible']);

            return ['id' => hash('sha256', json_encode([$email, $first['name'], $group->pluck('ref')->values()->all(), $first['context'], (bool) $eligible], JSON_UNESCAPED_UNICODE)), 'email' => $email, 'name' => $first['name'],
                'eligible' => (bool) $eligible, 'reason' => $eligible ? null : (! $sameName ? 'Shared address has different names. Review separately.' : 'Missing email permission/verification, invalid address, unsubscribe or prior bounce.'),
                'schools' => $matches->pluck('school')->unique()->values()->all(),
                'attendance' => $matches->pluck('attendance')->unique()->implode(', '),
                'source_refs' => $group->pluck('ref')->values()->all(), 'context' => $first['context']];
        })->filter()->sortBy('name')->values();
    }

    private function row($type, $id, $name, $email, $visitId, $eligible, $attendance, EducationVisit $v, $registeredAt): array
    {
        $name = preg_replace('/\s+/u', ' ', trim($name));
        $parts = explode(' ', $name, 2);

        return ['email' => strtolower(trim($email)), 'name' => $name, 'eligible' => (bool) $eligible && $name !== '',
            'school' => $v->school, 'attendance' => $attendance, 'registered_on' => $registeredAt?->timezone('Asia/Manila')->toDateString(),
            'ref' => ['type' => $type, 'id' => $id, 'visit_id' => $visitId],
            'context' => ['full_name' => $name, 'first_name' => $parts[0], 'last_name' => $parts[1] ?? '', 'email' => strtolower(trim($email)),
                'school' => $v->school, 'event_name' => $v->activity, 'event_date' => $v->schedule_confirmed ? $v->event_date?->format('j F Y') ?? 'Date to be confirmed' : 'Date to be confirmed',
                'event_time' => $v->schedule_confirmed ? $v->event_time ?? 'Time to be confirmed' : 'Time to be confirmed',
                'event_location' => $v->schedule_confirmed ? $v->location ?? 'Location to be confirmed' : 'Location to be confirmed']];
    }

    public function templates(): Collection
    {
        return MessageTemplate::active()->orderBy('name')->get()->filter(fn ($t) => $t->hasChannel('email') && $this->compatible($t))->values();
    }

    public function compatible(MessageTemplate $t): bool
    {
        if (! $this->hasContent($t->email_subject, $t->email_body)) {
            return false;
        }

        // Never offer credential/account templates to a person who may have no account.
        preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/', $t->email_subject.' '.$t->email_body, $m);

        return empty(array_diff(array_map(fn ($s) => strtolower(trim($s)), $m[1]), self::TOKENS));
    }

    private function render(string $text, array $context, bool $html): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn ($m) => $html ? e($context[strtolower($m[1])] ?? '') : ($context[strtolower($m[1])] ?? ''), $text);
    }

    private function hasContent(?string $subject, ?string $body): bool
    {
        $hasText = fn (string $text) => preg_match('/[^\s\p{Z}\x{200B}\x{FEFF}]/u', $text) === 1;
        $body = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $body ?? '');

        return $hasText($subject ?? '') && ($hasText(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            || preg_match('/<img\b[^>]*\ssrc\s*=\s*["\']?[^"\'\s>]/i', $body) === 1);
    }

    public function templateSnapshot(MessageTemplate $t): array
    {
        $mailer = config('mail.mailers.'.config('mail.default'), []);
        $from = $mailer['from'] ?? config('mail.from');
        $replyTo = $mailer['reply_to'] ?? config('mail.reply_to');
        $fromEmail = $t->from_email ?: $from['address'];
        $fromName = $t->from_email ? $t->from_name : ($from['name'] ?? null);
        $replyTo = match (true) {
            filled($t->reply_to_email) => ['address' => $t->reply_to_email, 'name' => $t->reply_to_name],
            filled(config('services.contact.reply_to')) => ['address' => config('services.contact.reply_to')],
            filled($replyTo['address'] ?? null) => $replyTo,
            default => ['address' => $fromEmail, 'name' => $fromName],
        };

        // Freeze the same shell the mailer would render, including effective footer and banner.
        $shell = view('emails.branded', [
            'subjectLine' => self::SUBJECT_MARKER,
            'bodyHtml' => self::BODY_MARKER,
            'bannerImage' => $t->banner_image,
            'footerImage' => $t->footer_image,
            'branding' => $t->branding,
        ])->render();

        return [
            'id' => $t->id, 'subject' => $t->email_subject, 'body' => $t->email_body,
            'body_html' => (new TemplatedMessage($t->email_subject, $t->email_body))->content()->with['bodyHtml'],
            'shell' => $shell, 'banner' => $t->banner_image, 'footer' => $t->footer_image, 'branding' => $t->branding,
            'from_email' => $fromEmail, 'from_name' => $fromName,
            'reply_to_email' => $replyTo['address'], 'reply_to_name' => $replyTo['name'] ?? null,
        ];
    }

    public function templateReview(array $snapshot): string
    {
        return hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), config('app.key'));
    }

    public function renderedMessage(array $snapshot, array $context): ?array
    {
        if (! isset($snapshot['shell'], $snapshot['body_html']) || ! $this->hasContent($snapshot['subject'] ?? null, $snapshot['body'] ?? null)) {
            return null;
        }
        $subject = $this->render($snapshot['subject'], $context, false);
        $body = $this->render($snapshot['body_html'], $context, true);
        if (! $this->hasContent($subject, $body)) {
            return null;
        }

        return ['subject' => $subject, 'body' => $body, 'html' => strtr($snapshot['shell'], [
            e(self::SUBJECT_MARKER) => e($subject), self::BODY_MARKER => $body,
        ])];
    }

    public function selected(array $filters, array $keys): Collection
    {
        $rows = $this->rows($filters)->whereIn('id', $keys);
        if ($rows->count() !== count($keys) || ! $rows->every(fn ($r) => $r['eligible'])) {
            throw ValidationException::withMessages(['recipient_keys' => 'The audience changed or contains ineligible recipients. Reload and review the list.']);
        }

        return $rows;
    }
}
