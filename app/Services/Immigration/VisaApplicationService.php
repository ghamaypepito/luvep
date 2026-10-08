<?php

namespace App\Services\Immigration;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Models\VisaApplication;
use App\Models\VisaType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Multi-visa: a client applies for one visa after another, each its own case
 * Lead. This service links those cases into a sequence (Visa 1, 2, …), and
 * starts the next application — copying the client's details + the documents
 * that the new visa's checklist also asks for, then closing (locking) the
 * previous application. Procedural case handling — not immigration advice.
 */
class VisaApplicationService
{
    /** Personal fields carried from one visa application to the next. */
    private const COPY_FIELDS = [
        'first_name', 'last_name', 'email', 'phone', 'dob', 'gender',
        'marital_status', 'citizenship', 'nationality', 'country_of_birth',
        'place_of_birth', 'passport_number', 'passport_expiry',
    ];

    /** Get (or lazily create) the registry row for a case — defaults to Visa 1. */
    public function ensureFor(Lead $lead): VisaApplication
    {
        return VisaApplication::firstOrCreate(
            ['lead_id' => $lead->id],
            [
                'root_lead_id' => $lead->id,
                'previous_lead_id' => null,
                'sequence' => 1,
                'visa_type' => $lead->inz_visa_type,
                'status' => VisaApplication::STATUS_ACTIVE,
                'opened_at' => $lead->immigration_converted_at ?? $lead->created_at ?? Carbon::now(),
            ],
        );
    }

    /** All applications in a client's chain, oldest first (for the switcher). */
    public function chainFor(Lead $lead)
    {
        $root = $this->ensureFor($lead)->root_lead_id;

        return VisaApplication::where('root_lead_id', $root)
            ->orderBy('sequence')
            ->get();
    }

    /** UI-ready chain + this case's position/lock state for the Case Profile. */
    public function chainData(Lead $lead): array
    {
        $current = $this->ensureFor($lead);

        $chain = VisaApplication::where('root_lead_id', $current->root_lead_id)
            ->orderBy('sequence')
            ->with('lead:id,lead_id,first_name,last_name,inz_visa_type,immigration_stage')
            ->get()
            ->map(fn (VisaApplication $v) => [
                'lead_id' => $v->lead_id,
                'sequence' => $v->sequence,
                'visa_type' => $v->visa_type ?: $v->lead?->inz_visa_type,
                'status' => $v->status,
                'outcome' => $v->outcome ?: $v->lead?->immigration_stage,
                'is_current' => $v->lead_id === $lead->id,
            ])->values()->all();

        return [
            'sequence' => $current->sequence,
            'total' => count($chain),
            'closed' => $current->isClosed(),
            // Can start the next visa only from the latest, still-open application
            // that has reached a positive outcome (Approved Visa).
            'can_start_next' => ! $current->isClosed()
                && $current->sequence === collect($chain)->max('sequence')
                && $lead->immigration_stage === 'Approved Visa',
            'chain' => $chain,
        ];
    }

    /**
     * Start the client's NEXT visa application: a fresh case Lead carrying their
     * details + the documents the new visa also needs, linked after the current
     * one, with the current application closed (locked). Returns the new case.
     */
    public function startNextVisa(Lead $from, string $newVisaLabel, ?int $newVisaTypeId, ?User $by): Lead
    {
        return DB::transaction(function () use ($from, $newVisaLabel, $newVisaTypeId, $by) {
            $current = $this->ensureFor($from);

            // 1. New case Lead — copy the client's personal details.
            $attrs = ['lead_id' => Lead::generateLeadId()];
            foreach (self::COPY_FIELDS as $f) {
                $attrs[$f] = $from->{$f} ?? null;
            }
            $attrs = array_merge($attrs, [
                'is_immigration_case' => true,
                'inz_visa_type' => $newVisaLabel,
                'immigration_converted_at' => Carbon::now(),
                'immigration_converted_by' => $by?->id,
                'stage_updated_at' => Carbon::now(),
                'stage_updated_by' => $by?->id,
                'source' => 'second-visa',
                'status' => 'New Leads',
            ]);
            $new = Lead::create($attrs);

            // 2. Carry over the documents the new visa's checklist also asks for.
            $this->copyMatchingDocuments($from, $new, $newVisaLabel, $newVisaTypeId);

            // 3. Register the new application after the current one.
            VisaApplication::create([
                'lead_id' => $new->id,
                'root_lead_id' => $current->root_lead_id,
                'previous_lead_id' => $from->id,
                'sequence' => $current->sequence + 1,
                'visa_type' => $newVisaLabel,
                'status' => VisaApplication::STATUS_ACTIVE,
                'opened_at' => Carbon::now(),
            ]);

            // 4. Close (lock) the previous application.
            $current->status = VisaApplication::STATUS_CLOSED;
            $current->outcome = $from->immigration_stage;
            $current->closed_at = Carbon::now();
            $current->closed_by = $by?->id;
            $current->save();

            return $new;
        });
    }

    /** Copy the previous case's uploads whose checklist key the new visa shares. */
    private function copyMatchingDocuments(Lead $from, Lead $to, string $newVisaLabel, ?int $newVisaTypeId): void
    {
        $vt = $newVisaTypeId
            ? VisaType::find($newVisaTypeId)
            : VisaType::where('name', $newVisaLabel)->first();

        $newKeys = ($vt && is_array($vt->checklist_items))
            ? collect($vt->checklist_items)->pluck('key')->filter()->all()
            : [];

        if (! $newKeys) {
            return;
        }

        $docs = LeadDocument::where('lead_id', $from->id)
            ->where('source', LeadDocument::SOURCE_UPLOAD)
            ->whereIn('checklist_key', $newKeys)
            ->get();

        foreach ($docs as $d) {
            if (! $d->file_path || ! Storage::disk('local')->exists($d->file_path)) {
                continue;
            }
            $ext = pathinfo((string) $d->file_path, PATHINFO_EXTENSION);
            $newPath = "lead-documents/{$to->id}/".Str::random(40).($ext ? ".{$ext}" : '');
            Storage::disk('local')->copy($d->file_path, $newPath);

            LeadDocument::create([
                'lead_id' => $to->id,
                'checklist_key' => $d->checklist_key,
                'original_name' => $d->original_name,
                'file_path' => $newPath,
                'mime' => $d->mime,
                'size' => $d->size,
                'status' => LeadDocument::STATUS_SUBMITTED,
                'source' => LeadDocument::SOURCE_UPLOAD,
                'note' => 'Carried over from the previous visa application',
            ]);
        }
    }
}
