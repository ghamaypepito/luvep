<?php

namespace App\Http\Controllers\Immigration;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\Immigration\PassportPrefillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PassportPrefillController extends Controller
{
    private function guard(Request $request, Lead $lead): void
    {
        abort_unless($request->user() && ! $request->attributes->has('user_view_actor')
            && PassportPrefillService::enabledFor($request->user()), 403);
        abort_unless(Lead::immigrationCase()->whereKey($lead->id)->exists(), 404);
    }

    public function latest(Request $request, Lead $lead, PassportPrefillService $service)
    {
        $this->guard($request, $lead);

        return response()->json($service->latest($lead, $request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function propose(Request $request, Lead $lead, PassportPrefillService $service)
    {
        $this->guard($request, $lead);

        return response()->json($service->propose($lead, $request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function apply(Request $request, Lead $lead, string $token, PassportPrefillService $service)
    {
        $this->guard($request, $lead);
        $validated = $request->validate(['confirmed' => 'required|accepted', 'fields' => 'required|array|min:1|max:7', 'fields.*' => ['required', 'string', 'distinct', Rule::in(PassportPrefillService::FIELDS)]]);

        $saved = $service->apply($lead, $request->user(), $token, $validated['fields']);
        $fresh = $lead->fresh();
        $personal = [];
        foreach (['first_name', 'middle_name', 'last_name', 'suffix', 'gender', 'marital_status', 'dob', 'email', 'phone', 'citizenship', 'residence_country', 'passport_number', 'passport_expiry', 'inz_client_number', 'inz_application_number', 'inz_medical_ref', 'nzer_number', 'ird_number', 'inz_lodged_at', 'target_lodgement_at'] as $field) {
            $value = $fresh->{$field};
            $personal[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) ($value ?? '');
        }

        return response()->json(['saved' => $saved, 'personal' => $personal])->header('Cache-Control', 'private, no-store');
    }

    public function document(Request $request, Lead $lead, int $document, PassportPrefillService $service)
    {
        $this->guard($request, $lead);
        $doc = $service->document($lead, $document);
        $source = $service->privateSource($doc, $request->user());

        return response()->file(Storage::disk('local')->path($source['path']), [
            'Content-Type' => $source['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->setPrivate();
    }
}
