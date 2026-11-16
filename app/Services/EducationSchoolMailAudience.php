<?php

namespace App\Services;

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

    public function rows(array $filters, ?string $onlyEmail = null): Collection
    {
        $ids = $filters['visit_ids'];
        $visits = EducationVisit::whereIn('id', $ids)->where('status', '!=', 'cancelled')->get()->keyBy('id');
        $rows = collect();
        $confirmation = $filters['purpose'] === 'email_follow_up' ? 'follow_up_confirmed_at' : 'newsletter_confirmed_at';
        $subscriptions = EducationEventEmailSubscription::whereNull('exited_at')->whereNotNull($confirmation)->when($onlyEmail, fn ($q) => $q->whereRaw('LOWER(TRIM(email)) = ?', [$onlyEmail]))->get()->keyBy('registration_id');
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
                $r->attendance_status === 'attended' ? ($r->attendance_source === 'automatic' ? 'Recorded attended (automatic)' : 'Recorded attended') : 'Registered', $visits[$r->education_visit_id]));
        }
        // Programme registrations and RSVPs are independent. Never manufacture Lead links.
        // Recorded-attendance mode uses RSVP evidence only.
        if ($filters['audience'] === 'registered') {
            foreach (EducationEventRegistration::whereIn('education_visit_id', $visits->keys())->whereHas('lead')->when($onlyEmail, fn ($q) => $q->whereRaw('LOWER(TRIM('.$q->getQuery()->getGrammar()->wrap('contact_details->email').')) = ?', [$onlyEmail]))->orderBy('id')->get() as $r) {
                $c = $r->contact_details ?? [];
                $name = trim(($c['first_name'] ?? '').' '.($c['last_name'] ?? ''));
                $rows->push($this->row('registration', $r->id, $name, $c['email'] ?? '', $r->education_visit_id,
                    $r->consented_at && ($r->{$filters['purpose']} || (isset($subscriptions[$r->id])
                        && $subscriptions[$r->id]->lead_id === $r->lead_id
                        && strtolower(trim($subscriptions[$r->id]->email)) === strtolower(trim($c['email'] ?? '')))), 'Programme registration', $visits[$r->education_visit_id]));
            }
        }

        return $rows->groupBy('email')->map(function ($group, $email) use ($suppressed) {
            $first = $group->first();
            $sameName = $group->map(fn ($r) => EducationRsvpWorkflow::nameKey($r['name']))->unique()->count() === 1;
            $eligible = ! isset($suppressed[$email]) && filter_var($email, FILTER_VALIDATE_EMAIL) && $sameName && $group->every(fn ($r) => $r['eligible']);

            return ['id' => hash('sha256', json_encode([$email, $first['name'], $group->pluck('ref')->values()->all(), $first['context'], (bool) $eligible], JSON_UNESCAPED_UNICODE)), 'email' => $email, 'name' => $first['name'],
                'eligible' => (bool) $eligible, 'reason' => $eligible ? null : (! $sameName ? 'Shared address has different names. Review separately.' : 'Missing email permission/verification, invalid address, unsubscribe or prior bounce.'),
                'schools' => $group->pluck('school')->unique()->values()->all(),
                'attendance' => $group->pluck('attendance')->unique()->implode(', '),
                'source_refs' => $group->pluck('ref')->values()->all(), 'context' => $first['context']];
        })->sortBy('name')->values();
    }

    private function row($type, $id, $name, $email, $visitId, $eligible, $attendance, EducationVisit $v): array
    {
        $name = preg_replace('/\s+/u', ' ', trim($name));
        $parts = explode(' ', $name, 2);

        return ['email' => strtolower(trim($email)), 'name' => $name, 'eligible' => (bool) $eligible && $name !== '',
            'school' => $v->school, 'attendance' => $attendance, 'ref' => ['type' => $type, 'id' => $id, 'visit_id' => $visitId],
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
        // Never offer credential/account templates to a person who may have no account.
        preg_match_all('/\{\{\s*([^{}]+?)\s*\}\}/', ($t->email_subject ?? '').' '.($t->email_body ?? ''), $m);

        return empty(array_diff(array_map(fn ($s) => strtolower(trim($s)), $m[1]), self::TOKENS));
    }

    public function render(string $text, array $context, bool $html): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', fn ($m) => $html ? e($context[strtolower($m[1])] ?? '') : ($context[strtolower($m[1])] ?? ''), $text);
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
