<?php

namespace App\Traits;

use App\Models\Lead;
use App\Models\LeadDocument;
use App\Models\LeadNote;
use App\Models\LeadStudyPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Shared "Add Lead" handler used by the Sales and Education portals. Mirrors
 * the columns of the team's Sales Dashboard sheet: a single name, a status,
 * pre-screening + goal-setting captures (stored as LeadNote "kinds" so they
 * render in the leads-table expander), a program offered (a study plan), and
 * the calendar / link columns that live on the lead itself.
 */
trait CreatesDashboardLead
{
    /**
     * Validation rules for the dashboard add-lead form. Status is validated
     * against the calling controller's pipeline list.
     */
    protected function dashboardLeadRules(array $statuses): array
    {
        return [
            'first_name'            => 'required|string|max:120',
            'last_name'             => 'nullable|string|max:120',
            'suffix'                => 'nullable|string|max:30',
            'email'                 => 'nullable|email|max:200',
            'phone'                 => 'nullable|string|max:40',
            'highest_qualification' => 'nullable|string|max:200',
            // Recruiting agent this lead is attributed to (staff-picked on the
            // sales add form). Must be a registered user with the 'agent' role.
            'agent_id'              => ['nullable', \Illuminate\Validation\Rule::exists('users', 'id')->where('role', 'agent')],
            'status'                => ['nullable', \Illuminate\Validation\Rule::in($statuses)],
            'assessment_date'       => 'nullable|date',
            'pre_screened_by'       => 'nullable|string|max:120',
            'pre_screening_notes'   => 'nullable|string|max:2000',
            'program_offered'       => 'nullable|string|max:200',
            'goal_setting_by'       => 'nullable|string|max:120',
            'goal_setting_status'   => ['nullable', \Illuminate\Validation\Rule::in([
                'Consultation Done', 'For Proposal', 'Proposal Sent', 'No Show',
            ])],
            'goal_setting_notes'    => 'nullable|string|max:2000',
            'calendar_date'         => 'nullable|date',
            'client_info_link'      => 'nullable|url|max:500',
            'call_update_form_link' => 'nullable|url|max:500',
        ];
    }

    /**
     * Staff who can pre-screen / run goal-setting — sales, education and
     * admin users, for the "by" dropdowns on the Add Lead form.
     */
    protected function dashboardStaff(array $roles = ['sales', 'education', 'admin'])
    {
        return \App\Models\User::whereIn('role', $roles)
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'avatar_path']);
    }

    /**
     * Create a lead + its dashboard side-records from validated form data.
     */
    protected function createDashboardLead(array $data): Lead
    {
        // Everything below must succeed together — a failed study-plan or
        // note insert should not leave an orphaned lead behind.
        return \Illuminate\Support\Facades\DB::transaction(fn () => $this->persistDashboardLead($data));
    }

    private function persistDashboardLead(array $data): Lead
    {
        // first_name / last_name are NOT NULL. The suffix (Jr., III, …) has
        // no column of its own, so append it to the surname for display:
        // "Juan" / "Dela Cruz Jr." renders as "Juan Dela Cruz Jr.".
        $first = trim($data['first_name']);
        $last  = trim(($data['last_name'] ?? '') . ' ' . ($data['suffix'] ?? ''));

        $status = $data['status'] ?? 'New Leads';

        // When a recruiting Agent adds the lead, stamp them as its owner so
        // it scopes to their portal and shows on the Sales Agents tab. Other
        // roles (sales/education/admin) leave agent_id null.
        $creator = Auth::user();
        $agentId = ($creator && $creator->role === 'agent') ? $creator->id : ($data['agent_id'] ?? null);

        $lead = new Lead([
            'first_name'            => $first,
            'last_name'             => $last,
            'email'                 => $data['email'] ?? null,
            'phone'                 => $data['phone'] ?? null,
            'highest_qualification' => $data['highest_qualification'] ?? null,
            'status'                => $status,
            'stage'                 => $status,
            'source'                => 'manual',
            'agent_id'              => $agentId,
            'calendar_date'         => $data['calendar_date'] ?? null,
            'client_info_link'      => $data['client_info_link'] ?? null,
            'call_update_form_link' => $data['call_update_form_link'] ?? null,
        ]);

        // Human-friendly reference — collision-safe (see Lead::generateLeadId).
        $lead->lead_id = Lead::generateLeadId();
        $lead->save();

        // ASSESSMENT DATE → treat as the lead's creation date so it sorts and
        // reports alongside the dashboard sheet. saveQuietly avoids a second
        // audit entry for the timestamp tweak.
        if (! empty($data['assessment_date'])) {
            $lead->created_at = \Illuminate\Support\Carbon::parse($data['assessment_date']);
            $lead->saveQuietly();
        }

        // PROGRAM OFFERED → a study plan row (what the table reads back).
        // qualification_level is NOT NULL with no default; the dashboard
        // form doesn't capture it, so store an empty string.
        if (! empty($data['program_offered'])) {
            LeadStudyPlan::create([
                'lead_id'             => $lead->id,
                'preferred_course'    => $data['program_offered'],
                'qualification_level' => '',
            ]);
        }

        $user = Auth::user();

        // PRE-SCREENING — only when there's something to capture.
        if (! empty($data['pre_screened_by']) || ! empty($data['pre_screening_notes'])) {
            LeadNote::create([
                'lead_id'         => $lead->id,
                'user_id'         => $user?->id,
                'author_name'     => $user?->name,
                'author_role'     => $user?->role,
                'kind'            => 'pre_screen',
                'pre_screened_by' => $data['pre_screened_by'] ?? null,
                'body'            => ($data['pre_screening_notes'] ?? null) ?: 'Pre-screening recorded.',
            ]);
        }

        // GOAL-SETTING — only when there's something to capture.
        if (! empty($data['goal_setting_by']) || ! empty($data['goal_setting_status']) || ! empty($data['goal_setting_notes'])) {
            LeadNote::create([
                'lead_id'             => $lead->id,
                'user_id'             => $user?->id,
                'author_name'         => $user?->name,
                'author_role'         => $user?->role,
                'kind'                => 'goal_setting',
                'goal_setting_by'     => $data['goal_setting_by'] ?? null,
                'goal_setting_status' => $data['goal_setting_status'] ?? null,
                'body'                => ($data['goal_setting_notes'] ?? null) ?: 'Goal-setting recorded.',
            ]);
        }

        return $lead;
    }

    /**
     * Maps the four "Add Lead" document upload fields to their checklist keys,
     * mirroring the public registration form (CV / Passport / Diploma /
     * Transcript). Stored as LeadDocument rows so they show in the lead's
     * Documents checklist.
     */
    private const DASHBOARD_DOC_MAP = [
        'cv_files'         => 'acad.cv',
        'passport_files'   => 'pers.passport',
        'diploma_files'    => 'acad.degree_diploma',
        'transcript_files' => 'acad.transcript',
    ];

    /**
     * Validation rules for the optional Add-Lead document uploads. Merge into
     * the calling controller's validator.
     */
    protected function dashboardDocumentRules(): array
    {
        $rules = [];
        foreach (array_keys(self::DASHBOARD_DOC_MAP) as $field) {
            $rules[$field] = 'nullable|array|max:10';
            $rules["{$field}.*"] = 'file|mimes:pdf,doc,docx,xls,csv,jpg,jpeg,png,gif|max:10240';
        }

        return $rules;
    }

    /**
     * Persist any uploaded Add-Lead documents as LeadDocument rows against the
     * matching checklist key. Files go on the private 'local' disk, the same
     * place staff/lead checklist uploads live.
     */
    protected function storeDashboardLeadDocuments(Lead $lead, Request $request): void
    {
        foreach (self::DASHBOARD_DOC_MAP as $field => $checklistKey) {
            if (! $request->hasFile($field)) {
                continue;
            }
            foreach ((array) $request->file($field) as $file) {
                if (! $file) {
                    continue;
                }
                $path = $file->store("lead-documents/{$lead->id}", 'local');
                LeadDocument::create([
                    'lead_id'       => $lead->id,
                    'checklist_key' => $checklistKey,
                    'original_name' => $file->getClientOriginalName(),
                    'file_path'     => $path,
                    'mime'          => $file->getMimeType(),
                    'size'          => $file->getSize(),
                    'status'        => LeadDocument::STATUS_SUBMITTED,
                    'source'        => 'manual',
                    'uploaded_by'   => Auth::id(),
                ]);
            }
        }
    }
}
