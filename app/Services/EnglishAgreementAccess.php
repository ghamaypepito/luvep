<?php

namespace App\Services;

use App\Models\LeadDocument;
use App\Services\Immigration\CaseChecklistService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

final class EnglishAgreementAccess
{
    public static function assertAvailable(LeadDocument $document): void
    {
        abort_unless($document->checklist_key === 'agree.engagement_english' && $document->isEducationAgreement(), 404);
        abort_if($document->status === LeadDocument::STATUS_REJECTED, 404);
        abort_if(in_array($document->checklist_key, app(CaseChecklistService::class)->hiddenKeysFor($document->lead), true), 404);
        abort_unless($document->mime === 'application/pdf' && $document->file_path && Storage::disk('local')->exists($document->file_path), 404);
    }

    public static function link(LeadDocument $document, ?Carbon $expires = null): string
    {
        self::assertAvailable($document);

        return URL::temporarySignedRoute('english-agreement.show', $expires ?? now()->addDays(7), ['document' => $document->id]);
    }

    public static function emailContext(?LeadDocument $document): array
    {
        if (! $document || $document->checklist_key !== 'agree.engagement_english') {
            return [];
        }
        $url = self::link($document);

        // Older editable templates use tracker_url; the override applies only
        // to this agreement email, never to the record's actual tracking code.
        return ['agreement_url' => $url, 'tracker_url' => $url, 'client_portal_url' => $url];
    }
}
