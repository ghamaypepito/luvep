<?php

namespace App\Services\Immigration;

use App\Models\FinanceReceivable;
use App\Models\Lead;
use App\Models\LeadDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;
use ZipArchive;

class ArchivedCaseFiles
{
    public function snapshot(Lead $case): array
    {
        $records = [];
        foreach ([
            'lead_documents', 'lead_document_requests', 'lead_notes', 'lead_tasks',
            'case_dependents', 'agreements', 'case_financials', 'case_finance_payments',
            'case_threads', 'case_findings', 'case_finding_runs', 'case_form_assignments',
            'case_attestations', 'case_views', 'case_step_states', 'case_payments',
            'case_partner_recommendation', 'document_format_case', 'potential_cases',
            'lead_contact_profiles', 'lead_education_exps', 'lead_study_plans',
            'lead_proposals', 'lead_tag_lead', 'visa_applications', 'installment_plans', 'engagement_pack_archives',
            'finance_receivables', 'email_replies',
        ] as $table) {
            $records[$table] = DB::table($table)->where('lead_id', $case->id)->get();
        }

        $documents = $records['lead_documents']->pluck('id');
        $records['payment_proof_reviews'] = DB::table('payment_proof_reviews')->whereIn('document_id', $documents)
            ->orWhereIn('invoice_document_id', $documents)->orWhereIn('finance_payment_id', $records['case_finance_payments']->pluck('id'))->get();
        $records['finance_payments'] = DB::table('finance_payments')->where('paymentable_type', (new FinanceReceivable)->getMorphClass())
            ->whereIn('paymentable_id', $records['finance_receivables']->pluck('id'))->get();

        $records['linked_dependants'] = DB::table('case_dependents')->where('linked_lead_id', $case->id)
            ->get(['id', 'lead_id', 'relationship']);
        $records['pre_screenings'] = DB::table('pre_screenings')->where('lead_id', $case->id)
            ->orWhere('converted_lead_id', $case->id)->get();
        $records['installments'] = DB::table('installments')
            ->whereIn('installment_plan_id', $records['installment_plans']->pluck('id'))->get();
        $records['lead_task_attachments'] = DB::table('lead_task_attachments')
            ->whereIn('lead_task_id', $records['lead_tasks']->pluck('id'))->get();
        $roots = DB::table('media_folders')->where('lead_id', $case->id)->pluck('id');
        $records['media_folders'] = DB::table('media_folders')->whereIn('id', $roots)->orWhereIn('root_id', $roots)->get();
        $records['media_files'] = DB::table('media_files')->whereIn('folder_id', $records['media_folders']->pluck('id'))
            ->get(['id', 'folder_id', 'original_name', 'file_path', 'mime', 'size', 'created_at']);
        $records['activity'] = DB::table('activity_logs')->where('entity_type', Lead::class)->where('entity_id', $case->id)
            ->get(['id', 'action', 'description', 'created_at', 'actor_name']);

        $files = [];
        $this->add($files, 'passport', $case->passport_path);
        foreach (IntakeDocumentMigrator::leadPaths($case) as $paths) {
            foreach ($paths as $path) {
                $this->add($files, 'application-uploads', $path, disks: ['public']);
            }
        }
        foreach ($records['lead_documents'] as $document) {
            $this->add($files, 'documents/'.$document->id, $document->file_path, $document->original_name);
            $this->add($files, 'signatures/'.$document->id, $document->client_signature_path, disks: ['local']);
            $notes = json_decode($document->notes ?? '', true);
            $this->add($files, 'issued/'.$document->id, $notes['issued_agreement']['file_path'] ?? null);
        }
        foreach ($records['lead_notes'] as $note) {
            foreach (json_decode($note->attachments ?? '', true) ?? [] as $index => $attachment) {
                $path = $attachment['path'] ?? null;
                if (! empty($attachment['media_file_id'])) {
                    $path = $records['media_files']->firstWhere('id', $attachment['media_file_id'])?->file_path;
                }
                $this->add($files, "notes/{$note->id}/{$index}", $path, $attachment['original_name'] ?? null);
            }
        }
        foreach ($records['lead_task_attachments'] as $attachment) {
            $this->add($files, 'tasks/'.$attachment->id, $attachment->file_path, $attachment->original_filename, ['public']);
        }
        foreach ($records['agreements'] as $agreement) {
            $this->add($files, 'agreements/'.$agreement->id.'/issued', $agreement->pdf_path);
            $this->add($files, 'agreements/'.$agreement->id.'/signed', $agreement->signed_pdf_path);
        }
        foreach ($records['media_files'] as $file) {
            $this->add($files, 'media/'.$file->id, $file->file_path, $file->original_name, ['local']);
        }

        return [
            'case' => $this->withoutCredentials($case->getAttributes()),
            'records' => array_map(fn ($rows) => $rows->map(fn ($row) => $this->withoutCredentials((array) $row)), $records),
            'files' => $files,
        ];
    }

    public function zip(array $snapshot): string
    {
        $path = tempnam(sys_get_temp_dir(), 'archived-case-');
        if ($path === false) {
            throw new RuntimeException('Could not create the case download.');
        }

        $zip = new ZipArchive;
        try {
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not open the case download.');
            }

            $files = $snapshot['files'];
            $snapshot['files'] = [];
            $snapshot['unavailable_files'] = [];
            foreach ($files as $entry => $file) {
                $disk = $this->findDisk($file);
                if ($disk === null) {
                    $snapshot['unavailable_files'][] = $entry;

                    continue;
                }
                if (! $zip->addFile(Storage::disk($disk)->path($file['path']), $entry)) {
                    throw new RuntimeException('Could not add a case file to the download.');
                }
                $snapshot['files'][$entry] = $file['path'];
            }

            $manifest = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            if (! $zip->addFromString('case-records.json', $manifest) || ! $zip->close()) {
                throw new RuntimeException('Could not finish the case download.');
            }

            return $path;
        } catch (Throwable $error) {
            @unlink($path);

            throw $error;
        }
    }

    public function deleteUnshared(array $files): void
    {
        foreach ($files as $file) {
            $path = $file['path'];
            $name = '%'.basename($path).'%';
            if (LeadDocument::where('file_path', $path)->orWhere('client_signature_path', $path)->orWhere('notes->issued_agreement->file_path', $path)->exists()
                || Lead::withTrashed()->where('passport_path', $path)->orWhereLike('education_notes', $name)->exists()
                || DB::table('invoice_replacements')->where('previous_path', $path)->exists()
                || DB::table('agreements')->where('pdf_path', $path)->orWhere('signed_pdf_path', $path)->exists()
                || DB::table('media_files')->where('file_path', $path)->exists()
                || DB::table('tenant_documents')->where('path', $path)->exists()
                || DB::table('lead_task_attachments')->where('file_path', $path)->exists()
                || DB::table('lead_notes')->whereLike('attachments', $name)->exists()
                || $this->referencedByIntake($path, $name)) {
                continue;
            }
            foreach ($file['disks'] as $disk) {
                if ($this->findDisk(['path' => $path, 'disks' => [$disk]]) !== null
                    && ! Storage::disk($disk)->delete($path)) {
                    throw new RuntimeException('Could not remove an archived case file from storage.');
                }
            }
        }
    }

    private function referencedByIntake(string $path, string $name): bool
    {
        $prefix = strstr($path, '/', true);
        if (in_array($prefix, ['resident-intakes', 'work-intakes', 'student-intakes', 'visitor-intakes', 'family-intakes'], true)) {
            return DB::table(str_replace('-', '_', $prefix))->whereLike('document_files', $name)->exists();
        }

        return false;
    }

    private function add(array &$files, string $folder, ?string $path, ?string $name = null, array $disks = ['local', 'public']): void
    {
        if ($path) {
            $name = basename(str_replace('\\', '/', $name ?: $path));
            $safe = preg_replace('/[^\pL\pN ._()-]/u', '_', $name) ?: 'file';
            $files[$folder.'/'.(trim($safe, ' .') ?: 'file')] = ['path' => $path, 'disks' => $disks];
        }
    }

    private function findDisk(array $file): ?string
    {
        foreach ($file['disks'] as $diskName) {
            $disk = Storage::disk($diskName);
            $root = realpath($disk->path(''));
            $path = realpath($disk->path($file['path']));
            if ($root !== false && $path !== false && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && is_file($path) && is_readable($path)) {
                return $diskName;
            }
        }

        return null;
    }

    private function withoutCredentials(array $row): array
    {
        return array_filter($row, fn ($key) => ! preg_match('/token|password|tracking_code|manage_code/', $key), ARRAY_FILTER_USE_KEY);
    }
}
