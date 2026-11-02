<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Services\PaymentProofService;
use Illuminate\Http\Request;

class PaymentProofController extends Controller
{
    public function options(Request $request, Lead $lead, LeadDocument $document, PaymentProofService $proofs)
    {
        abort_unless($proofs->canRead($request->user(), $lead), 403);
        abort_unless($document->lead_id === $lead->id && $document->source_variant === 'proof_of_payment', 404);

        return response()->json(array_merge($proofs->reviewOptions($document), ['can_review' => $proofs->canReview($request->user()), 'today' => now()->toDateString()]))
            ->header('Cache-Control', 'private, no-store');
    }
}
