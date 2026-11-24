<?php

namespace App\Http\Controllers;

use App\Models\LeadDocument;
use App\Services\Education\CostBreakdownRates;
use App\Services\Education\CostBreakdownSections;
use App\Services\MediaDrive\Access;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EducationCostBreakdownController extends Controller
{
    public const KEY = 'edu.cost_breakdown';

    private function leads(Request $request)
    {
        return app(Access::class)->leads($request->user())->where(function ($q) {
            $q->inLeadPipeline()->orWhere(fn ($students) => $students->inStudentsRegister());
        });
    }

    public function index(Request $request)
    {
        $q = trim($request->validate(['q' => ['nullable', 'string', 'max:150']])['q'] ?? '');
        $leads = $this->leads($request)->when($q !== '', function ($query) use ($q) {
            foreach (preg_split('/\s+/u', $q) as $word) {
                $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $word).'%';
                $query->where(fn ($w) => $w->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)->orWhere('lead_id', 'like', $like));
            }
        })->orderBy('first_name')->limit(50)->get(['id', 'lead_id', 'first_name', 'last_name', 'residence_country']);
        $countries = collect(json_decode(file_get_contents(resource_path('data/education-cost-countries.json')), true));
        $aliases = ['UK' => 'GB', 'USA' => 'US', 'UNITED STATES OF AMERICA' => 'US'];

        return response()->json([
            'clients' => $leads->map(function ($lead) use ($countries, $aliases) {
                $residence = trim((string) $lead->residence_country);
                $search = strtoupper($residence);
                $country = $countries->first(fn ($country) => strtoupper($country['name']) === $search || $country['code'] === ($aliases[$search] ?? $search));
                $currency = $country['currency'] ?? null;

                return ['id' => $lead->id, 'name' => trim($lead->first_name.' '.$lead->last_name), 'reference' => $lead->lead_id,
                    'country' => $country['name'] ?? $residence,
                    'category' => $residence === '' ? '' : match ($country['code'] ?? null) {
                        'PH' => 'philippines', 'NZ' => 'onshore', default => 'offshore'
                    },
                    'currency' => $currency && $currency !== 'XXX' ? $currency : ''];
            }),
            'documents' => LeadDocument::where('checklist_key', self::KEY)->whereIn('lead_id', $this->leads($request)->select('id'))
                ->with('lead:id,first_name,last_name')->latest('id')->limit(50)->get()->map(fn ($doc) => [
                    'id' => $doc->id, 'name' => $doc->original_name, 'client' => trim($doc->lead->first_name.' '.$doc->lead->last_name),
                    'created_at' => $doc->created_at->toIso8601String(), 'download_url' => '/admin/documents/'.$doc->id.'/download',
                ]),
        ]);
    }

    public function rates(Request $request, CostBreakdownRates $rates)
    {
        $data = $request->validate(['source' => ['required', 'regex:/^[A-Z]{3}$/D'], 'target' => ['required', 'regex:/^[A-Z]{3}$/D']]);

        return response()->json($rates->quote($data['source'], $data['target']));
    }

    public function generate(Request $request, CostBreakdownRates $rates)
    {
        $data = $request->validate([
            'lead_id' => ['required', 'integer'], 'country' => ['required', 'string', 'max:100'],
            'category' => ['required', Rule::in(['philippines', 'onshore', 'offshore'])],
            'family' => ['required', Rule::in(['single', 'couple', 'couple_child'])],
            'source_currency' => ['required', 'regex:/^[A-Z]{3}$/D'], 'target_currency' => ['required', 'regex:/^[A-Z]{3}$/D'],
            'rate_token' => ['required', 'string', 'max:12000'], 'rows' => ['required', 'array', 'min:1', 'max:30'],
            'rows.*.description' => ['required', 'string', 'max:500'],
            'rows.*.section' => ['nullable', Rule::in(array_column(CostBreakdownSections::catalogue(), 'key'))],
            'rows.*.amount' => ['required_unless:rows.*.section,other_costs', 'nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'action' => ['required', Rule::in(['preview', 'download', 'save'])],
        ]);
        $lead = $this->leads($request)->findOrFail($data['lead_id']);
        $snapshot = $rates->snapshot($data['rate_token'], $data['source_currency'], $data['target_currency']);
        $sourceRate = $snapshot['rates'][$data['source_currency']]['rate'];
        $targetRate = $snapshot['rates'][$data['target_currency']]['rate'];
        $rows = collect($data['rows'])->map(function ($row) use ($sourceRate, $targetRate) {
            $amount = isset($row['amount']) ? (float) $row['amount'] : null;

            return ['description' => trim($row['description']), 'section' => $row['section'] ?? '',
                'amount' => $amount === null ? null : round($amount, 2),
                'nzd' => $amount === null ? null : round($amount / $sourceRate, 2),
                'local' => $amount === null ? null : round($amount / $sourceRate * $targetRate, 2)];
        })->all();
        $payload = ['client' => trim($lead->first_name.' '.$lead->last_name), 'country' => $data['country'],
            'category' => $data['category'], 'family' => $data['family'], 'source_currency' => $data['source_currency'],
            'target_currency' => $data['target_currency'], 'rows' => $rows, 'exchange' => $snapshot,
            'generated_at' => now()->toIso8601String(), ...CostBreakdownSections::layout($rows)];
        if ($data['action'] === 'preview') {
            return response()->json(['html' => view('agreements.education-cost-breakdown', $payload)->render()]);
        }
        $pdf = Pdf::loadView('agreements.education-cost-breakdown', $payload)->setPaper('a4')->output();
        $filename = 'Cost-Breakdown-'.Str::slug($payload['client']).'-'.now()->format('Ymd-His').'.pdf';
        if ($data['action'] === 'download') {
            return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"']);
        }
        $path = 'lead-documents/'.$lead->id.'/cost-breakdowns/'.Str::uuid().'.pdf';
        try {
            if (! Storage::disk('local')->put($path, $pdf)) {
                throw new \RuntimeException('Could not store breakdown');
            }
            $doc = DB::transaction(fn () => LeadDocument::create([
                'lead_id' => $lead->id, 'checklist_key' => self::KEY, 'original_name' => $filename, 'file_path' => $path,
                'mime' => 'application/pdf', 'size' => strlen($pdf), 'source' => LeadDocument::SOURCE_GENERATED,
                'source_variant' => 'education_cost_breakdown', 'status' => LeadDocument::STATUS_UNDER_REVIEW,
                'uploaded_by' => $request->user()->id, 'notes' => ['cost_breakdown_snapshot' => $payload],
            ]));
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return response()->json(['id' => $doc->id, 'download_url' => '/admin/documents/'.$doc->id.'/download'], 201);
    }
}
