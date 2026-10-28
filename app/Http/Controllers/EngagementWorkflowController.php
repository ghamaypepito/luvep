<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Services\Immigration\EngagementPackWorkflow;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EngagementWorkflowController extends Controller
{
    private function lead($id): Lead
    {
        $lead = Lead::findOrFail($id);
        abort_unless(Lead::immigrationCase()->whereKey($id)->exists()
            || \App\Models\PreScreening::where('lead_id', $id)->exists(), 404);

        return $lead;
    }

    public function prepare(Request $request, EngagementPackWorkflow $workflow, $id)
    {
        $data = $request->validate([
            'engagement_type' => ['required', Rule::in(['immigration_advice', 'initial_consultation', 'oia'])],
            'types' => 'required|array|min:1|max:5', 'types.*' => 'required|string|distinct',
            'signer_id' => 'required|integer|exists:users,id', 'assist_signer_id' => 'nullable|integer|exists:users,id',
            'fee_tier' => ['nullable', Rule::in(['normal', 'discounted'])],
            'fee_location' => ['nullable', Rule::in(['onshore', 'offshore'])],
            'include_gst' => 'sometimes|boolean', 'professional_fee' => 'nullable|numeric|min:0|max:9999999',
            'pack_id' => 'nullable|uuid',
            'consultation_date' => 'required_if:engagement_type,initial_consultation|nullable|date_format:Y-m-d',
            'consultation_time' => 'required_if:engagement_type,initial_consultation|nullable|date_format:H:i',
            'consultation_timezone' => 'required_if:engagement_type,initial_consultation|nullable|timezone',
            'meeting_link' => 'required_if:engagement_type,initial_consultation|nullable|url:https|max:1000',
        ]);

        return response()->json($workflow->prepare($this->lead($id), $data));
    }

    public function defaults(Request $request, $id)
    {
        $lead = $this->lead($id);
        $booking = \App\Models\Booking::where('lead_id', $lead->id)->whereNotNull('appointment_at')
            ->whereNotIn('status', ['cancelled', 'canceled'])->latest('appointment_at')->first();
        if (! $booking) {
            return response()->json([]);
        }
        $zone = in_array($booking->client_timezone, timezone_identifiers_list(), true) ? $booking->client_timezone : 'Pacific/Auckland';
        $at = $booking->appointment_at->copy()->timezone($zone);

        return response()->json([
            'consultation_date' => $at->format('Y-m-d'), 'consultation_time' => $at->format('H:i'),
            'consultation_timezone' => $zone, 'meeting_link' => $booking->meet_link ?? '',
        ]);
    }

    public function save(Request $request, EngagementPackWorkflow $workflow, $id)
    {
        $data = $request->validate(['token' => 'required|uuid']);

        return response()->json($workflow->save($this->lead($id), $data['token']));
    }

    public function review(Request $request, EngagementPackWorkflow $workflow, $id)
    {
        $data = $request->validate(['pack_id' => ['required', 'string', 'max:50', function ($attribute, $value, $fail) {
            if ($value !== 'legacy' && ! \Illuminate\Support\Str::isUuid($value)) {
                $fail('Invalid engagement pack.');
            }
        }]]);

        return response()->json($workflow->review($this->lead($id), $data['pack_id']));
    }

    public function send(Request $request, EngagementPackWorkflow $workflow, $id)
    {
        $data = $request->validate(['token' => 'required|uuid']);
        $workflow->send($this->lead($id), $data['token']);

        return response()->json(['message' => 'Engagement emailed to the client.']);
    }

    public function preview(Request $request, EngagementPackWorkflow $workflow, $id, string $kind, string $token, int $index)
    {
        abort_unless(in_array($kind, ['prepared', 'review'], true), 404);
        $data = $workflow->recalled($this->lead($id), $kind, $token);
        $file = $data['files'][$index] ?? null;
        abort_unless($file, 404);

        return response(base64_decode($file['bytes']))->withHeaders([
            'Content-Type' => 'application/pdf', 'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$file['name'].'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
