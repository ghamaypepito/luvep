<?php

namespace App\Services\Accommodation;

use App\Models\AccommodationAgreement as Agreement;
use App\Models\AccommodationViewingAttendee as Attendee;
use App\Models\AccommodationViewingSlot as Slot;
use App\Models\EoiSubmission;
use App\Models\Property;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class ExaltEmailActions
{
    public function options(string $action, string $email): Collection
    {
        if ($action === 'viewing') {
            return Property::where('is_active', true)->orderBy('name')->get()->map(fn (Property $property) => [
                'id' => $property->id, 'label' => $property->address ?: $property->name,
                'fields' => ['Property Name' => $property->name, 'Property Address' => $property->address ?: $property->name],
            ]);
        }

        if ($action === 'attendance') {
            return $this->attendees($email)->latest('id')->limit(50)->get()->filter(fn (Attendee $attendee) => $attendee->slot->startsAt()->isFuture())
                ->map(fn (Attendee $attendee) => $this->attendanceOption($attendee))->values();
        }

        if ($action === 'pre_tenancy') {
            return $this->submissions($email)->latest('id')->limit(50)->get()->map(fn (EoiSubmission $submission) => [
                'id' => $submission->id, 'label' => $submission->full_legal_name.' · '.$submission->property_interested,
                'fields' => [],
            ])->prepend(['id' => 0, 'label' => 'General Pre-Tenancy Form', 'fields' => []]);
        }

        if (in_array($action, ['agreement', 'signed_agreement'], true)) {
            return $this->agreements($action, $email)->latest('id')->limit(50)->get()
                ->filter(fn (Agreement $agreement) => $action === 'agreement' || Storage::disk('local')->exists($agreement->signed_pdf_path))
                ->values()->map(fn (Agreement $agreement) => [
                    'id' => $agreement->id, 'label' => '#'.$agreement->id.' · '.$agreement->client_name.' · '.$agreement->property_address,
                    'fields' => $action === 'agreement' ? array_filter(['Property Address' => $agreement->property_address]) : [],
                ]);
        }

        if ($action === 'invoice') {
            return $this->invoices($email)->filter(fn ($invoice) => Storage::disk('local')->exists($invoice->pdf_path))
                ->values()->map(fn ($invoice) => $this->invoiceOption($invoice));
        }

        if ($action === 'welcome') {
            return $this->tenants($email)->latest('id')->limit(50)->get()->map(fn (Tenant $tenant) => [
                'id' => $tenant->id, 'label' => $tenant->display_name.' · '.($tenant->property?->address ?: $tenant->property?->name),
                'fields' => array_filter(['Property Address' => $tenant->property?->address ?: $tenant->property?->name]),
            ]);
        }

        return collect();
    }

    public function resolve(string $action, string $email, ?int $id, bool $preview): array
    {
        if ($action === '') {
            return [[], []];
        }

        $option = $id === null ? null : $this->options($action, $email)->firstWhere('id', $id);
        if ($option === null) {
            throw ValidationException::withMessages(['action_id' => 'Select an available record for this recipient.']);
        }

        $buttons = match ($action) {
            'viewing' => ['Book a Room / Property Viewing' => $preview ? '#' : URL::signedRoute('accommodation.viewing-booking', $id)],
            'attendance' => [
                'Confirm Attendance' => $preview ? '#' : $this->attendanceLink($id, $email, 'confirmed'),
                'Decline Attendance' => $preview ? '#' : $this->attendanceLink($id, $email, 'declined'),
            ],
            'pre_tenancy' => ['Complete the Pre-Tenancy Form' => $preview ? '#' : ($id === 0
                ? route('pre-tenancy.general')
                : route('pre-tenancy.show', $this->submissions($email)->findOrFail($id)->ensurePublicToken()))],
            'agreement' => ['Review and Sign Agreement' => $preview ? '#' : $this->agreements($action, $email)->findOrFail($id)->signingUrl()],
            'signed_agreement' => ['Download Signed Agreement' => $preview ? '#' : route('agreement.sign.signed-pdf',
                $this->agreements($action, $email)->findOrFail($id)->signing_token)],
            'invoice' => ['View or Download Invoice' => $preview ? '#' : URL::temporarySignedRoute(
                'accommodation.email.invoice', now()->addDays(30), ['invoice' => $id, 'recipient' => $this->recipientHash($email)])],
            default => [],
        };

        return [$option['fields'], $buttons];
    }

    public function attendanceLink(int $id, string $email, string $response): string
    {
        $attendee = $this->attendees($email)->findOrFail($id);

        return URL::temporarySignedRoute('accommodation.email.attendance', $attendee->slot->startsAt(), [
            'attendee' => $attendee->id, 'response' => $response,
            'recipient' => $this->recipientHash($email),
        ]);
    }

    public function recipientHash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }

    public function invoiceDownload(int $id, string $recipient)
    {
        abort_unless(Schema::hasTable('accommodation_rental_invoices'), 404);
        $invoice = DB::table('accommodation_rental_invoices as invoices')
            ->join('accommodation_tenants as tenants', 'tenants.id', '=', 'invoices.tenant_id')
            ->where('invoices.id', $id)->where('invoices.kind', 'move-in')
            ->first(['invoices.reference', 'invoices.pdf_path', 'tenants.email']);
        abort_unless($invoice && $invoice->email && hash_equals($this->recipientHash($invoice->email), $recipient)
            && Storage::disk('local')->exists($invoice->pdf_path), 404);

        return Storage::disk('local')->download($invoice->pdf_path, $invoice->reference.'.pdf', [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function attendees(string $email): Builder
    {
        return Attendee::with('slot')->whereRaw('LOWER(email) = ?', [$email])
            ->where('status', Attendee::CONFIRMED)
            ->whereHas('slot', fn ($slots) => $slots->where('viewing_date', '>=', CarbonImmutable::today(Slot::TIMEZONE)->toDateString()));
    }

    private function attendanceOption(Attendee $attendee): array
    {
        $slot = $attendee->slot;
        $date = $slot->startsAt()->format('l, j F Y');
        $time = $slot->startsAt()->format('g:i a');

        return [
            'id' => $attendee->id, 'label' => $slot->property_address.' · '.$date.' · '.$time,
            'fields' => ['Property Address' => $slot->property_address, 'Viewing Date' => $date, 'Viewing Time' => $time],
        ];
    }

    private function submissions(string $email): Builder
    {
        return EoiSubmission::whereRaw('LOWER(email) = ?', [$email])->whereNull('pre_tenancy_form_completed_at');
    }

    private function agreements(string $action, string $email): Builder
    {
        return Agreement::whereNotNull('signing_token')
            ->when($action === 'signed_agreement',
                fn ($query) => $query->where('status', Agreement::STATUS_SIGNED)->whereNotNull('signed_pdf_path'),
                fn ($query) => $query->whereIn('status', [Agreement::STATUS_SENT, Agreement::STATUS_VIEWED]))
            ->where(fn ($query) => $query
                ->whereRaw('LOWER('.$query->getQuery()->getGrammar()->wrap('data->recipient_email').') = ?', [$email])
                ->orWhere(fn ($fallback) => $fallback->whereNull('data->recipient_email')
                    ->where(fn ($source) => $source
                        ->whereHas('preTenancyForm', fn ($forms) => $forms->whereRaw('LOWER(email) = ?', [$email]))
                        ->orWhereHas('eoiSubmission', fn ($forms) => $forms->whereRaw('LOWER(email) = ?', [$email])))));
    }

    private function invoices(string $email): Collection
    {
        if (! Schema::hasTable('accommodation_rental_invoices')) {
            return collect();
        }

        return DB::table('accommodation_rental_invoices as invoices')
            ->join('accommodation_tenants as tenants', 'tenants.id', '=', 'invoices.tenant_id')
            ->whereRaw('LOWER(tenants.email) = ?', [$email])
            ->where('invoices.kind', 'move-in')->orderByDesc('invoices.id')->limit(50)
            ->get(['invoices.id', 'invoices.reference', 'invoices.details', 'invoices.pdf_path']);
    }

    private function invoiceOption(object $invoice): array
    {
        $details = json_decode($invoice->details, true, flags: JSON_THROW_ON_ERROR);

        return [
            'id' => $invoice->id, 'label' => $invoice->reference.' · '.$details['property'],
            'fields' => [
                'Total Amount Due' => 'NZ$'.number_format($details['total_cents'] / 100, 2),
                'Payment Due Date' => CarbonImmutable::parse($details['due_date'])->format('j F Y'),
            ],
        ];
    }

    private function tenants(string $email): Builder
    {
        return Tenant::with('property')->whereRaw('LOWER(email) = ?', [$email])->where('current_status', 'active');
    }
}
