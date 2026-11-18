<?php

namespace App\Services\Immigration;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\User;
use App\Notifications\EngagementSignatureUploaded;
use App\Services\CommunicationService;
use App\Services\MediaDrive\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffEngagementSignature
{
    public static function permitted(User $user): bool
    {
        return $user->is_active && ($user->isAdmin() || in_array($user->role, ['immigration', 'immigration_manager', 'immigration_adviser'], true));
    }

    public function internalRecipients()
    {
        return User::whereIn('id', config('engagement-signature.internal_user_ids', []))->where('is_active', true)
            ->get()->filter(fn ($user) => self::permitted($user))->values();
    }

    public function upload(Request $request, int $documentId): array
    {
        $user = $request->user();
        abort_unless(self::permitted($user), 403);
        $doc = LeadDocument::findOrFail($documentId);
        abort_unless(app(Access::class)->leads($user)->whereKey($doc->lead_id)->exists()
            && Lead::immigrationCase()->whereKey($doc->lead_id)->exists(), 404);

        $data = $request->validate([
            'signature_image' => 'required|image|mimes:png,jpg,jpeg|max:2048|dimensions:min_width=1,min_height=1,max_width=4000,max_height=4000',
            'signer_name' => 'required|string|max:120', 'authorized' => 'required|accepted',
            'notify_client' => 'required|boolean', 'notify_internal' => 'required|boolean',
        ]);
        $internal = $this->internalRecipients();
        if ($data['notify_internal'] && $internal->isEmpty()) {
            throw ValidationException::withMessages(['notify_internal' => 'Internal recipients must be confirmed and configured before notifying the team.']);
        }
        if ($data['notify_client'] && ! filter_var($doc->lead->email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['notify_client' => 'This client has no valid email address. Correct it or select no client email.']);
        }

        return Cache::lock('engagement:case:'.$doc->lead_id, 90)->block(5, function () use ($request, $doc, $data, $user, $internal) {
            $createdPaths = [];
            try {
                $signed = DB::transaction(function () use ($request, $doc, $data, $user, &$createdPaths) {
                    $lead = Lead::whereKey($doc->lead_id)->lockForUpdate()->firstOrFail();
                    $document = LeadDocument::whereKey($doc->id)->lockForUpdate()->firstOrFail();
                    $type = str_replace('engagement:', '', (string) $document->source_variant);
                    abort_unless(str_starts_with((string) $document->source_variant, 'engagement:') && EngagementDocumentGenerator::signable($type), 422, 'Choose a signable engagement agreement.');
                    abort_if($document->client_signed_at, 409, 'This agreement is already signed. It cannot be replaced.');
                    abort_if($document->status === LeadDocument::STATUS_REJECTED, 422, 'Rejected agreements cannot receive signatures.');
                    $notes = $document->notes ?? [];
                    $sentAt = isset($notes['engagement_pack_id']) ? ($notes['engagement_sent_at'] ?? null) : $lead->engagement_sent_at;
                    abort_unless($sentAt, 422, 'Issue the engagement before recording its signature.');
                    if (empty($notes['engagement_payload']) || ! is_array($notes['engagement_payload'])) {
                        throw ValidationException::withMessages(['signature_image' => 'This legacy agreement has no issued content snapshot. Have the agreement reviewed before applying a signature.']);
                    }
                    abort_unless(Storage::disk('local')->exists($document->file_path), 422, 'The original agreement file is unavailable.');
                    $image = $request->file('signature_image');
                    $extension = $image->getMimeType() === 'image/png' ? 'png' : 'jpeg';
                    $bytes = file_get_contents($image->getRealPath());
                    $signaturePath = 'signatures/staff-upload-'.Str::uuid().'.'.$extension;
                    $signedPath = 'lead-documents/'.$lead->id.'/signed-engagement-'.Str::uuid().'.pdf';
                    $originalHash = hash('sha256', Storage::disk('local')->get($document->file_path));
                    $pdf = app(EngagementDocumentGenerator::class)->pdfBinary($lead, $type, [
                        'signer_id' => $document->engagement_signer_id, 'payload_snapshot' => $notes['engagement_payload'],
                        'client_signature' => 'data:image/'.$extension.';base64,'.base64_encode($bytes),
                        'client_signed_date' => now()->format('d/m/Y'),
                    ]);
                    foreach ([$signaturePath => $bytes, $signedPath => $pdf] as $path => $content) {
                        $createdPaths[] = $path;
                        if (! Storage::disk('local')->put($path, $content)) {
                            throw new \RuntimeException('Could not store the signature.');
                        }
                    }
                    $notes['staff_signature_upload'] = [
                        'method' => 'staff_upload', 'uploaded_by' => $user->id, 'uploaded_by_name' => $user->name,
                        'uploaded_at' => now()->toIso8601String(), 'staff_ip' => $request->ip(),
                        'authorization_confirmed' => true, 'signer_name' => $data['signer_name'],
                        'original_file_path' => $document->file_path, 'original_file_sha256' => $originalHash,
                        'signature_sha256' => hash('sha256', $bytes), 'notify_client' => (bool) $data['notify_client'],
                        'notify_internal' => (bool) $data['notify_internal'],
                    ];
                    $document->forceFill(['notes' => $notes, 'file_path' => $signedPath, 'size' => strlen($pdf),
                        'client_signature_path' => $signaturePath, 'client_signed_at' => now(),
                        'client_signer_name' => $data['signer_name']])->save();
                    $lead->recordStaffActivity('Engagement signature uploaded by staff for document #'.$document->id);
                    if ($type === 'written_agreement') {
                        $lead->advanceImmigrationStage('Agreement Signed', ['For Assessment', 'Endorsed', 'Agreement Sent', 'For Agreement & Invoice', 'Request for Information'], $user->id, notify: false);
                    }

                    return $document;
                });
            } catch (\Throwable $e) {
                foreach ($createdPaths as $path) {
                    Storage::disk('local')->delete($path);
                }
                throw $e;
            }

            // Dedicated notifications only: do not fire generic signing/stage automation.
            $delivery = ['client' => 'not_requested', 'internal' => 'not_requested'];
            if ($data['notify_client']) {
                try {
                    $name = e(trim($signed->lead->first_name.' '.$signed->lead->last_name));
                    $log = app(CommunicationService::class)->sendComposedEmail($signed->lead->email, 'Engagement signature uploaded',
                        '<p>Hello '.$name.',</p><p>The signature you provided has been uploaded to your engagement agreement by our staff. Please contact our team if you have any questions.</p>', [], false, $signed->lead_id);
                    $delivery['client'] = $log->status === 'failed' ? 'failed' : 'queued';
                } catch (\Throwable $e) {
                    $delivery['client'] = 'failed';
                    Log::warning('Staff signature client notification failed', ['document_id' => $signed->id, 'exception' => get_class($e)]);
                }
            }
            if ($data['notify_internal']) {
                $delivery['internal'] = 'sent';
                foreach ($internal as $recipient) {
                    try {
                        $recipient->notify(new EngagementSignatureUploaded([
                            'title' => 'Engagement signature uploaded', 'body' => $user->name.' uploaded a client-provided signature for '.trim($signed->lead->first_name.' '.$signed->lead->last_name).'.',
                            'lead_id' => $signed->lead_id, 'document_id' => $signed->id, 'uploaded_by' => $user->id,
                            'link' => '/portal/immigration/cases/engagement',
                        ]));
                    } catch (\Throwable $e) {
                        $delivery['internal'] = 'failed';
                        Log::warning('Staff signature internal notification failed', ['document_id' => $signed->id, 'recipient_id' => $recipient->id]);
                    }
                }
            }
            try {
                DB::transaction(function () use ($signed, $delivery) {
                    $fresh = LeadDocument::whereKey($signed->id)->lockForUpdate()->firstOrFail();
                    $notes = $fresh->notes;
                    $notes['staff_signature_upload']['notification_result'] = $delivery;
                    $fresh->forceFill(['notes' => $notes])->save();
                });
            } catch (\Throwable $e) {
                Log::warning('Staff signature notification audit update failed', ['document_id' => $signed->id]);
            }

            return ['message' => 'Signature uploaded and saved.', 'notifications' => $delivery];
        });
    }
}
