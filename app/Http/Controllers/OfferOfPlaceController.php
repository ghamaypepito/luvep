<?php

namespace App\Http\Controllers;

use App\Models\LeadDocument;
use App\Support\LeadAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class OfferOfPlaceController extends Controller
{
    private function documents(Request $request): Builder
    {
        $role = $request->user()->role;
        abort_unless(in_array($role, ['admin', 'super_admin', 'sales', 'education'], true), 403);

        return LeadDocument::query()
            ->where('checklist_key', 'acad.offer_of_place')
            ->where('source', LeadDocument::SOURCE_UPLOAD)
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('file_path')->where('file_path', '!=', ''))
                ->orWhereNotNull('media_file_id'))
            ->whereHas('lead', function ($q) use ($role) {
                if ($role === 'education') {
                    $q->where('is_student', true);
                } elseif ($role === 'sales') {
                    foreach (['is_student', 'is_immigration_case', 'is_english_student', 'is_accommodation_client'] as $flag) {
                        $q->where(fn ($q) => $q->where($flag, false)->orWhereNull($flag));
                    }
                }
            });
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:150',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
        ]);
        $timezone = config('app.timezone', 'UTC');
        $query = $this->documents($request)->with('lead:id,first_name,last_name,lead_id');
        if ($search = trim($filters['search'] ?? '')) {
            $query->whereHas('lead', fn ($q) => $q->where(fn ($q) => $q
                ->where('first_name', 'like', "%{$search}%")
                ->orWhere('last_name', 'like', "%{$search}%")
                ->orWhere('lead_id', 'like', "%{$search}%")));
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'], $timezone)->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<', Carbon::parse($filters['to'], $timezone)->addDay()->startOfDay());
        }
        $base = $request->url();
        $clients = (clone $query)->distinct()->count('lead_id');
        $documents = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString()
            ->through(fn ($doc) => [
                'id' => $doc->id,
                'client_name' => trim($doc->lead->first_name.' '.$doc->lead->last_name),
                'client_code' => $doc->lead->lead_id,
                'filename' => $doc->original_name,
                'uploaded_at' => $doc->created_at?->toIso8601String(),
                'status' => $doc->status,
                'view_url' => $base.'/'.$doc->id.'/download?inline=1',
                'download_url' => $base.'/'.$doc->id.'/download',
            ]);
        $page = $request->is('portal/education/*') ? 'portal/education/OffersOfPlace'
            : ($request->is('portal/sales/*') ? 'portal/sales/OffersOfPlace' : 'admin/OffersOfPlace');

        return inertia($page, compact('documents', 'filters', 'timezone', 'clients', 'base'));
    }

    public function download(Request $request, int $document)
    {
        $doc = $this->documents($request)->whereKey($document)->with('lead')->firstOrFail();
        abort_unless(LeadAccess::canView($request->user(), $doc->lead), 404);

        return app(LeadDocumentController::class)->download($request, $doc->id);
    }
}
