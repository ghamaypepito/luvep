<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FiresEnquiryAutomation;
use App\Http\Controllers\Concerns\SyncsIntakeToCase;
use App\Models\Assessment;
use App\Models\ResidentIntake;
use App\Support\IntakeVisaTypeMap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ResidentIntakeController extends Controller
{
    use FiresEnquiryAutomation;
    use SyncsIntakeToCase;

    /** Document-checklist keys that may carry uploaded PDFs (plus an "other" bucket). */
    private const DOCUMENT_KEYS = [
        'passport', 'visa_copies', 'contracts', 'payslips',
        'ird_summary', 'education_certs', 'cv', 'other',
    ];

    private const DOCUMENT_LABELS = [
        'passport' => 'Passport (all pages)',
        'visa_copies' => 'All NZ visa copies',
        'contracts' => 'NZ employment contracts + JD',
        'payslips' => 'Payslips — first 2 mo + latest 1 mo',
        'ird_summary' => 'IRD summary of earnings (monthly)',
        'education_certs' => 'Education certificates / transcripts',
        'cv' => 'CV (NZ + overseas history)',
        'other' => 'Other supporting documents',
    ];

    /**
     * Show the public Resident Intake form.
     */
    public function showForm()
    {
        return inertia('visa/ResidentIntakePage');
    }

    /**
     * Validation rules shared by `store` (initial submission) and `updateFromEditLink`
     * (applicant editing via a shareable token link).
     */
    private function intakeRules(bool $requireTerms = true): array
    {
        return [
            'terms_accepted' => ($requireTerms ? 'required|accepted' : 'nullable|boolean'),

            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'dob' => 'required|date',
            'nationality' => 'required|string|max:120',
            'birth_certificate_available' => 'nullable|in:Yes,No',
            'birth_certificate_reason' => 'nullable|required_if:birth_certificate_available,No|string|max:500',
            'email' => 'required|email|max:255',
            'phone' => 'required|string|max:25',

            'passport_number' => 'required|string|max:60',
            'passport_expiry' => 'required|date',
            'issuing_country' => 'required|string|max:120',
            'face_image_consent' => 'nullable|boolean',

            'current_visa_type' => 'required|in:AEWV,Essential Skills,Work to Residence,Other',
            'current_visa_other' => 'nullable|required_if:current_visa_type,Other|string|max:255',
            'current_visa_expiry' => 'required|date',
            'nz_arrival_date' => 'required|date',
            'previous_nz_visa_history' => 'nullable|string',

            'job_title' => 'required|string|max:255',
            'employer_name' => 'nullable|string|max:255',
            'employment_start' => 'required|date',
            'employment_type' => 'required|string|max:120',
            'hourly_rate' => 'required|numeric|min:0',

            'highest_qualification' => 'required|string|max:120',
            'institution_name' => 'nullable|string|max:255',
            'country_of_study' => 'nullable|string|max:120',
            'nzqa_status' => 'nullable|string|max:120',
            'nzqa_iqa_reference' => 'nullable|required_if:nzqa_status,Yes — IQA completed|string|max:60',

            'nz_skilled_years' => 'required|numeric|min:0',
            'total_skilled_years' => 'required|numeric|min:0',
            'career_summary' => 'nullable|string',

            'english_evidence' => 'required|string|max:120',
            'english_test_score' => 'nullable|required_if:english_evidence,IELTS|required_if:english_evidence,TOEFL|required_if:english_evidence,PTE Academic|string|max:30',
            'english_test_date' => 'nullable|required_if:english_evidence,IELTS|required_if:english_evidence,TOEFL|required_if:english_evidence,PTE Academic|date',

            'include_family' => 'required|string|max:60',
            'family_members' => 'nullable|array',
            'family_members.*.full_name' => 'nullable|required_with:family_members.*.relationship|string|max:255',
            'family_members.*.relationship' => 'nullable|in:Spouse / partner,Dependent child',
            'family_members.*.dob' => 'nullable|date',
            'family_members.*.passport_number' => 'nullable|string|max:60',

            'documents' => 'nullable|array',
            'document_files' => 'nullable|array',
            'document_files.*' => 'nullable|array',
            'document_files.*.*' => 'nullable|file|mimes:pdf|max:10240',

            'character_health_disclosure' => 'nullable|string',
            'medical_cert_recent' => 'nullable|in:Yes,No',
            'medical_cert_submitted_last' => 'nullable|in:Yes,No',
            'medical_cert_type' => 'nullable|required_if:medical_cert_submitted_last,Yes|in:General Medical,X-Ray Medical Examination',
            'police_certificate_available' => 'nullable|in:Yes,No',
            'other_notes' => 'nullable|string',
        ];
    }

    /**
     * Store each uploaded document under the intake's folder on the private disk
     * and return [key => [paths...]] alongside the new documents-checklist map.
     */
    private function persistUploadedFiles(Request $request, string $intakeId, array $existingFiles = [], array $documents = []): array
    {
        $files = $existingFiles;
        foreach ((array) $request->file('document_files', []) as $key => $uploads) {
            if (! in_array($key, self::DOCUMENT_KEYS, true)) {
                continue;
            }
            $newPaths = [];
            foreach ((array) $uploads as $file) {
                if ($file instanceof \Illuminate\Http\UploadedFile) {
                    $newPaths[] = $file->store("resident-intakes/{$intakeId}", 'local');
                }
            }
            if (! empty($newPaths)) {
                $existing = isset($files[$key])
                    ? (is_array($files[$key]) ? $files[$key] : [$files[$key]])
                    : [];
                $files[$key] = array_merge($existing, $newPaths);
                if ($key !== 'other') {
                    $documents[$key] = true; // attached file implies "I have this"
                }
            }
        }

        return [$files, $documents];
    }

    /**
     * Store a submitted Resident Intake.
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->intakeRules());

        // Uploaded PDFs are handled manually; keep them out of mass-assignment.
        unset($validated['document_files']);

        // FormData submissions (when files are attached) stringify booleans —
        // normalise the checklist back to real booleans.
        $documents = collect((array) $request->input('documents', []))
            ->map(fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN))
            ->all();

        try {
            DB::beginTransaction();

            $intakeId = 'RI-'.strtoupper(uniqid());

            [$storedFiles, $documents] = $this->persistUploadedFiles($request, $intakeId, [], $documents);

            $intake = ResidentIntake::create(array_merge(
                $validated,
                [
                    'intake_id' => $intakeId,
                    'status' => 'Submitted',
                    'documents' => $documents ?: null,
                    'document_files' => $storedFiles ?: null,
                ]
            ));

            // Tracking-only Assessment row — payment + booking stay
            // disabled (see AssessmentController::simulatePay), but the
            // portal Assessments page + Convert-to-Case flow need a
            // paired Assessment to hang journey state and the assessment
            // ID off. The Pay/Book redirects below are intentionally
            // commented out: payment intake is dormant until Stripe is
            // wired up in a future build.
            $visaType = IntakeVisaTypeMap::resolve(ResidentIntake::class);
            if (! $visaType) {
                Log::warning('VisaType not found for Resident intake; skipping Assessment creation.', [
                    'intake_id' => $intake->id,
                    'intake_class' => ResidentIntake::class,
                ]);
            } else {
                Assessment::createForIntake($intake, $visaType);
            }

            DB::commit();

            $this->fireEnquiryCaptured($intake, 'Resident Visa (SMC)');

            $this->syncIntakeToExistingCase($intake, 'Resident Visa (SMC)');

            // return redirect()->route('assessment.pay', $assessment->token);
            return back()->with('intake_submitted', 'Resident Visa (SMC)');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Resident intake storage failed', ['error' => $e->getMessage()]);

            return redirect()->back()->withErrors([
                'error' => 'Failed to submit intake. Please try again.',
            ]);
        }
    }

    /**
     * Admin: generate (or fetch) a shareable edit-link token for the given intake.
     * The applicant uses this URL to open the form pre-filled with their data
     * and submit revisions / additional PDFs without logging in.
     */
    public function generateEditLink($id)
    {
        $intake = ResidentIntake::findOrFail($id);

        if (empty($intake->edit_token)) {
            $intake->edit_token = Str::random(48);
            $intake->save();
        }

        return back()->with([
            'edit_link_intake_id' => $intake->id,
            'edit_link_url' => url('/resident-interest/edit/'.$intake->edit_token),
        ]);
    }

    /**
     * Public: render the intake form pre-filled with the intake referenced by {token}.
     */
    public function showEditForm(string $token)
    {
        $intake = ResidentIntake::where('edit_token', $token)->firstOrFail();

        return inertia('visa/ResidentIntakePage', [
            'editIntake' => $intake,
            'editToken' => $token,
        ]);
    }

    /**
     * Public: update the intake referenced by {token}.
     * New PDF uploads are appended to whatever's already stored.
     */
    public function updateFromEditLink(Request $request, string $token)
    {
        $intake = ResidentIntake::where('edit_token', $token)->firstOrFail();

        // Editing doesn't re-require the terms-accept checkbox — that's part of the
        // original submission. Everything else is validated identically.
        $validated = $request->validate($this->intakeRules(requireTerms: false));

        unset($validated['document_files'], $validated['terms_accepted']);

        $documents = collect((array) $request->input('documents', []))
            ->map(fn ($v) => filter_var($v, FILTER_VALIDATE_BOOLEAN))
            ->all();

        try {
            DB::beginTransaction();

            $existingFiles = is_array($intake->document_files) ? $intake->document_files : [];
            [$mergedFiles, $documents] = $this->persistUploadedFiles($request, $intake->intake_id, $existingFiles, $documents);

            $intake->update(array_merge($validated, [
                'documents' => $documents ?: $intake->documents,
                'document_files' => $mergedFiles ?: null,
            ]));

            DB::commit();

            return redirect()->back()->with([
                'success' => true,
                'intake_id' => $intake->intake_id,
                'edited' => true,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Resident intake edit-link update failed', ['error' => $e->getMessage()]);

            return redirect()->back()->withErrors([
                'error' => 'Failed to save your changes. Please try again.',
            ]);
        }
    }

    /**
     * Pick the Inertia page path based on whether the viewer is an admin
     * (lives in /admin chrome) or an immigration-role user (immigration portal).
     */
    private function immigrationPagePath(string $page): string
    {
        return auth()->user()?->isAdmin()
            ? "admin/Immigration/{$page}"
            : "portal/immigration/{$page}";
    }

    /**
     * List all resident intakes — shared by admins and immigration-role staff.
     */
    public function adminIndex()
    {
        $intakes = ResidentIntake::latest()->get();

        return inertia($this->immigrationPagePath('ResidentIntakes'), [
            'intakes' => $intakes,
        ]);
    }

    /**
     * Show a single intake — shared by admins and immigration-role staff.
     */
    public function adminShow($id)
    {
        $intake = ResidentIntake::findOrFail($id);

        // Detect if the applicant's email already matches a Lead that
        // has been converted to an immigration case — the unified
        // detail page surfaces a "Converted to case" shortcut in the
        // sidebar when so.
        $email = strtolower(trim((string) ($intake->email ?? '')));
        $lead = null;
        if ($email !== '') {
            $lead = \App\Models\Lead::where('is_immigration_case', true)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first(['id', 'lead_id', 'first_name', 'last_name', 'status']);
        }

        // Paired Assessment (Pay → Book funnel state) + its Booking.
        $assessment = \App\Models\Assessment::with('booking')
            ->where('intakeable_type', ResidentIntake::class)
            ->where('intakeable_id', $intake->id)
            ->first();

        return inertia($this->immigrationPagePath('ResidentIntakeDetails'), [
            'type' => 'resident',
            'intake' => $intake->toArray(),
            // Documents payload — the unified DocumentsCard reads
            // `labels` (chrome), `ticked` (checkbox state), `files`
            // (uploaded files keyed by doc kind), and `other_label`.
            'documents' => [
                'labels' => self::DOCUMENT_LABELS,
                'ticked' => $intake->documents ?? (object) [],
                'files' => $intake->document_files ?? (object) [],
                'other_label' => 'Other supporting documents',
                'base' => "/admin/immigration/resident-intakes/{$intake->id}/documents",
            ],
            'assessment' => $assessment ? [
                'id' => $assessment->id,
                'status' => $assessment->status,
                'token' => $assessment->token,
                'paid_at' => $assessment->paid_at,
                'booking' => $assessment->booking ? [
                    'id' => $assessment->booking->id,
                    'status' => $assessment->booking->status,
                    'appointment_date' => $assessment->booking->appointment_date,
                    'appointment_time' => $assessment->booking->appointment_time,
                ] : null,
            ] : null,
            'linkedLead' => $lead ? [
                'id' => $lead->id,
                'lead_id' => $lead->lead_id,
                'name' => trim("{$lead->first_name} {$lead->last_name}") ?: 'Unknown',
                'status' => $lead->status,
            ] : null,
        ]);
    }

    /**
     * Admin: serve one of an intake's uploaded document PDFs.
     *
     * Each {key} may carry an array of file paths; {index} (default 0) picks one.
     * The PDF is streamed inline so it opens in the browser tab.
     * Pass ?download=1 to force a file download instead.
     */
    public function downloadDocument(Request $request, $id, string $key, $index = 0)
    {
        $intake = ResidentIntake::findOrFail($id);

        $files = $intake->document_files ?? [];
        abort_unless(isset($files[$key]), 404);

        // Tolerate legacy single-string entries as well as the array-of-paths shape.
        $entry = $files[$key];
        $paths = is_array($entry) ? array_values($entry) : [$entry];

        $i = (int) $index;
        abort_unless(isset($paths[$i]), 404);
        $path = $paths[$i];

        abort_unless(Storage::disk('local')->exists($path), 404);

        $label = self::DOCUMENT_LABELS[$key] ?? $key;
        $suffix = count($paths) > 1 ? ' ('.($i + 1).')' : '';
        $filename = $intake->intake_id.' - '.$label.$suffix.'.pdf';

        if ($request->boolean('download')) {
            return Storage::disk('local')->download($path, $filename);
        }

        return Storage::disk('local')->response($path, $filename, [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
