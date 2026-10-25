<?php

namespace App\Services;

use App\Models\EducationRsvp;
use App\Models\EducationRsvpSavedList;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EducationAttendance
{
    public const KEYS = ['school', 'visit_id', 'email', 'attendance_status', 'verification', 'kind'];

    public function filters(array $input): array
    {
        foreach (self::KEYS as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }
        $filters = Validator::make($input, ['school' => 'nullable|string|max:180', 'visit_id' => 'nullable|integer|min:1', 'email' => 'nullable|email:rfc|max:254', 'attendance_status' => ['nullable', Rule::in(['rsvp', 'attended', 'cancelled'])], 'verification' => ['nullable', Rule::in(['verified', 'unverified'])], 'kind' => ['nullable', Rule::in(['school', 'partnership'])]])->validate();
        $filters = array_filter($filters, fn ($v) => $v !== null && $v !== '');
        if (isset($filters['email'])) {
            $filters['email'] = mb_strtolower($filters['email']);
        }
        if (isset($filters['visit_id'])) {
            $filters['visit_id'] = (int) $filters['visit_id'];
        }
        ksort($filters);

        return $filters;
    }

    public function context(Request $request): array
    {
        $d = $request->validate(['list_id' => 'nullable|integer|exists:education_rsvp_saved_lists,id', '_filtered' => 'nullable|boolean']);
        $activeList = isset($d['list_id']) ? EducationRsvpSavedList::with('folder')->findOrFail($d['list_id']) : null;
        $filters = $this->filters($activeList && ! $request->boolean('_filtered') ? $activeList->filters : $request->only(self::KEYS));

        return ['attendanceFilters' => $filters, 'activeList' => $activeList, 'listModified' => $activeList && $filters !== $this->filters($activeList->filters)];
    }

    public function query(array $filters)
    {
        return EducationRsvp::with('visit')->when(isset($filters['school']), fn ($q) => $q->whereHas('visit', fn ($vst) => $vst->whereRaw('LOWER(TRIM(school)) = ?', [mb_strtolower($filters['school'])])))
            ->when($filters['visit_id'] ?? null, fn ($q, $v) => $q->where('education_visit_id', $v))->when($filters['kind'] ?? null, fn ($q, $v) => $q->whereHas('visit', fn ($vst) => $vst->where('kind', $v)))
            ->when($filters['email'] ?? null, fn ($q, $v) => $q->where('email', $v))->when($filters['attendance_status'] ?? null, fn ($q, $v) => $q->where('attendance_status', $v))
            ->when($filters['verification'] ?? null, fn ($q, $v) => $v === 'verified' ? $q->whereNotNull('verified_at') : $q->whereNull('verified_at'));
    }

    public static function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[\s\x00-\x20]*[=+@-]/u', $value) ? "'".$value : $value;
    }
}
