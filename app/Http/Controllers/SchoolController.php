<?php

namespace App\Http\Controllers;

use App\Models\School;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Schools catalog — institutions the Education team places students
 * into. CRUD surface mirrors the Programs catalog so the management
 * pattern stays consistent.
 */
class SchoolController extends Controller
{
    public function index(Request $request)
    {
        // Full rows so the inline Edit modal can prefill every field. The model
        // hides agreement_path; the agreement file itself only reaches admins
        // through the gated download route.
        $schools = School::orderBy('name')->get();
        // Pick the Inertia page name by URL prefix so the education
        // portal gets EducationLayout chrome while admins still get
        // AdminLayout — same controller, same data, two wrappers.
        $isEducation = $request->is('portal/education/*');
        $page = $isEducation ? 'portal/education/Schools' : 'admin/Schools';

        return inertia($page, [
            'schools' => $schools,
            // Base URL so the list can deep-link to each school's profile
            // under the right prefix (admin vs education portal).
            'portalBase' => $isEducation ? '/portal/education' : '/admin',
        ]);
    }

    /**
     * School profile — the full record (contact + portal details) on its own
     * page. The agreement file is admin-only: staff see the profile, but the
     * download link only appears (and only works) for admins.
     */
    public function show(Request $request, $id)
    {
        $school = School::findOrFail($id);
        $isEducation = $request->is('portal/education/*');
        $page = $isEducation ? 'portal/education/SchoolProfile' : 'admin/SchoolProfile';
        $canViewAgreement = (bool) optional($request->user())->isAtLeast('admin');

        return inertia($page, [
            'school' => $school->makeVisible('portal_password'),
            'portalBase' => $isEducation ? '/portal/education' : '/admin',
            'hasAgreement' => (bool) $school->agreement_path,
            'canViewAgreement' => $canViewAgreement,
            'workspace' => $isEducation && $request->user()?->is_active
                && ($request->user()->isAdmin() || $request->user()->role === 'education')
                && ! $request->attributes->has('user_view_actor')
                    ? \App\Services\SchoolWorkspaceData::forSchool($request, $school) : null,
            // Agreement always downloads through the admin route (admin-gated).
            'agreementUrl' => $school->agreement_path ? "/admin/schools/{$school->id}/agreement" : null,
        ]);
    }

    /** Stream the agreement file — admin/super-admin only. */
    public function downloadAgreement(Request $request, $id)
    {
        abort_unless(optional($request->user())->isAtLeast('admin'), 403);

        $school = School::findOrFail($id);
        abort_if(! $school->agreement_path || ! Storage::disk('local')->exists($school->agreement_path), 404, 'No agreement on file.');

        return Storage::disk('local')->download($school->agreement_path, $school->agreement_name ?: 'agreement');
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
        try {
            $data['contacts'] = $this->cleanContacts($data['contacts'] ?? []);
            $data = $this->applyLogoUpload($request, $data);
            $data = $this->applyAgreementUpload($request, $data);
            School::create($data);

            return back()->with('success', 'School added.');
        } catch (\Throwable $e) {
            Log::error('School store failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Could not add school.');
        }
    }

    public function update(Request $request, $id)
    {
        $school = School::findOrFail($id);
        $data = $this->validatePayload($request, $school->id);
        try {
            $data['contacts'] = $this->cleanContacts($data['contacts'] ?? []);
            $data = $this->applyLogoUpload($request, $data, $school);
            $data = $this->applyAgreementUpload($request, $data, $school);
            $school->update($data);

            return back()->with('success', 'School updated.');
        } catch (\Throwable $e) {
            Log::error('School update failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Could not update school.');
        }
    }

    public function destroy($id)
    {
        $school = School::findOrFail($id);
        if (\App\Models\SchoolWorkspaceRecord::where('school_id', $school->id)->exists()) {
            return back()->with('error', 'This school has management history. Set it inactive instead of deleting it.');
        }
        try {
            if ($school->agreement_path) {
                Storage::disk('local')->delete($school->agreement_path);
            }
            if ($school->logo_path) {
                Storage::disk('public')->delete($school->logo_path);
            }
            $school->delete();

            return back()->with('success', 'School removed.');
        } catch (\Throwable $e) {
            Log::error('School delete failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Could not remove school.');
        }
    }

    /**
     * Store a newly uploaded logo (public disk, replacing any prior one) and
     * fold its path into the write payload. No file sent = logo untouched.
     */
    private function applyLogoUpload(Request $request, array $data, ?School $school = null): array
    {
        // 'logo' is the upload input, not a column — never persist it directly.
        unset($data['logo']);

        if (! $request->hasFile('logo')) {
            return $data;
        }

        if ($school && $school->logo_path) {
            Storage::disk('public')->delete($school->logo_path);
        }

        $data['logo_path'] = $request->file('logo')->store('schools/logos', 'public');

        return $data;
    }

    /**
     * Store a newly uploaded agreement (private disk, admin-only download) and
     * fold its path/name into the write payload. When no file is sent, the
     * existing agreement is left untouched.
     */
    private function applyAgreementUpload(Request $request, array $data, ?School $school = null): array
    {
        // 'agreement_file' is the upload input, not a column — never persist it.
        unset($data['agreement_file']);

        if (! $request->hasFile('agreement_file')) {
            return $data;
        }

        // Replace any prior file so we don't orphan it on the disk.
        if ($school && $school->agreement_path) {
            Storage::disk('local')->delete($school->agreement_path);
        }

        $file = $request->file('agreement_file');
        $data['agreement_path'] = $file->store('school-agreements', 'local');
        $data['agreement_name'] = $file->getClientOriginalName();

        return $data;
    }

    /**
     * Keep only contact rows that carry at least one filled field, and trim
     * each to the four known keys. Returns a clean re-indexed list.
     *
     * @return array<int, array{name:?string, role:?string, email:?string, phone:?string}>
     */
    private function cleanContacts(array $contacts): array
    {
        return collect($contacts)
            ->map(fn ($c) => [
                'name' => trim((string) ($c['name'] ?? '')) ?: null,
                'role' => trim((string) ($c['role'] ?? '')) ?: null,
                'email' => trim((string) ($c['email'] ?? '')) ?: null,
                'phone' => trim((string) ($c['phone'] ?? '')) ?: null,
            ])
            ->filter(fn ($c) => $c['name'] || $c['role'] || $c['email'] || $c['phone'])
            ->values()
            ->all();
    }

    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => 'required|string|max:191',
            'country' => 'nullable|string|max:120',
            'city' => 'nullable|string|max:120',
            'website' => 'nullable|url|max:512',
            'description' => 'nullable|string|max:5000',
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif,svg|max:4096',
            // Contacts — a school can have several people (marketing, admissions…)
            'contacts' => 'nullable|array|max:50',
            'contacts.*.name' => 'nullable|string|max:191',
            'contacts.*.role' => 'nullable|string|max:191',
            'contacts.*.email' => 'nullable|string|max:191',
            'contacts.*.phone' => 'nullable|string|max:60',
            // Portal (enrolment portal login the team shares)
            'portal_username' => 'nullable|string|max:191',
            'portal_password' => 'nullable|string|max:191',
            'portal_link' => 'nullable|string|max:2000',
            // Agreement — an uploaded file, admin-access only
            'agreement_file' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
        ]);
    }
}
