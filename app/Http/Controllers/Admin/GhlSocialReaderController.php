<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ghl\GhlSocialReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GhlSocialReaderController extends Controller
{
    private function respond(array $result)
    {
        if (! $result['ok']) {
            return response()->json(['error' => $result['error'], 'message' => match ($result['error']) {
                'authorization' => 'GHL denied this read request. Check the EP integration permissions.','not_configured' => 'Save an EP GHL credential in Marketing Connectors.','ep_account_missing' => 'The Luv, Ep page is not connected to the EP GHL subaccount.','account_inactive' => 'Reconnect the Luv, Ep page in GHL before syncing.',default => 'GHL data is unavailable. Refresh to try again.'
            }], 502, ['Cache-Control' => 'private, no-store']);
        }unset($result['ok']);

        return response()->json($result, 200, ['Cache-Control' => 'private, no-store']);
    }

    public function accounts(GhlSocialReader $reader)
    {
        return $this->respond($reader->accounts());
    }

    public function posts(Request $request, GhlSocialReader $reader)
    {
        $data = $request->validate(['from' => 'sometimes|required|date_format:Y-m-d', 'to' => 'sometimes|required|date_format:Y-m-d', 'page' => 'sometimes|integer|min:1|max:1000', 'type' => ['sometimes', Rule::in(['all', 'scheduled', 'published', 'draft', 'in_review', 'failed'])]]);
        $from = $data['from'] ?? CarbonImmutable::now('Pacific/Auckland')->startOfMonth()->toDateString();
        $to = $data['to'] ?? CarbonImmutable::now('Pacific/Auckland')->endOfMonth()->toDateString();
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        abort_if($start > $end || $start->diffInDays($end) > 62, 422, 'Choose a date range of 63 days or fewer.');

        return $this->respond($reader->posts($from, $to, (int) ($data['page'] ?? 1), $data['type'] ?? 'all'));
    }

    public function insights(Request $request, GhlSocialReader $reader)
    {
        $data = $request->validate(['days' => ['sometimes', Rule::in([7, 30])]]);

        return $this->respond($reader->insights((int) ($data['days'] ?? 7)));
    }
}
