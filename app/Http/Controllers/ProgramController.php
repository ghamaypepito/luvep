<?php

namespace App\Http\Controllers;

use App\Models\Program;
use App\Models\School;
use App\Services\PromoFeed;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProgramController extends Controller
{
    private function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'institution' => 'nullable|string|max:255',
            'school_id' => 'nullable|integer|exists:schools,id',
            'location' => 'nullable|string|max:255',
            'level' => 'required|integer|min:1|max:10',
            'category' => 'required|in:certificate,diplomas,bachelors,masters',
            'industry' => 'nullable|string|max:255',
            'status' => 'required|in:draft,published,archived',
            'price_text' => 'nullable|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif|max:4096',
            'description' => 'nullable|string',
            'intake_months' => 'nullable|string|max:255',
            'duration_months' => 'nullable|integer|min:0',
            'credits' => 'nullable|integer|min:0',
            'residency_points' => 'nullable|integer|min:0',
            // Free text so it can hold a number (e.g. "25") or a word (e.g. "Unlimited").
            'hours_per_week' => 'nullable|string|max:50',
            'entry_requirements' => 'nullable|array',
            'entry_requirements.*' => 'nullable|array',
            'entry_requirements.*.intro' => 'nullable|string',
            'entry_requirements.*.bullets' => 'nullable|array',
            'entry_requirements.*.bullets.*' => 'nullable|string',
            'english_requirements' => 'nullable|string',
            'specialization' => 'nullable|string',
            'employment_outcomes' => 'nullable|array',
            'employment_outcomes.*' => 'nullable|array',
            'employment_outcomes.*.intro' => 'nullable|string',
            'employment_outcomes.*.bullets' => 'nullable|array',
            'employment_outcomes.*.bullets.*' => 'nullable|string',
            'post_study' => 'nullable|string',
            'other_benefits' => 'nullable|array',
            'other_benefits.*' => 'nullable|string',
            'fee_guide' => 'nullable|array',
            'fee_guide.*.region' => 'nullable|string|max:255',
            'fee_guide.*.fee' => 'nullable|numeric|min:0',
            'tuition_fee' => 'nullable|numeric|min:0',
            'tuition_fee_notes' => 'nullable|string|max:255',
            'tuition_fees' => 'nullable|array',
            'tuition_fees.*.label' => 'nullable|string|max:255',
            'tuition_fees.*.amount' => 'nullable|numeric|min:0',
            'tuition_fees.*.notes' => 'nullable|string|max:255',
            'insurance_fee' => 'nullable|numeric|min:0',
            'visa_processing_fee' => 'nullable|numeric|min:0',
            'living_expense' => 'nullable|numeric|min:0',
            'accommodation' => 'nullable|string|max:255',
        ];
    }

    private function appendImageUrl(Program $program): Program
    {
        $program->image_url = $program->image
            ? Storage::disk('public')->url($program->image)
            : null;

        return $program;
    }

    public function index()
    {
        // Eager-load the school so the table can show its name without an
        // N+1, and expose the picker options for the modal dropdown.
        $programs = Program::with('school:id,name')->latest()->get();
        $programs->each(fn ($p) => $this->appendImageUrl($p));

        $schools = School::orderBy('name')->get(['id', 'name']);

        return inertia('admin/Programs', [
            'programs' => $programs,
            'schools' => $schools,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules());

        if ($request->hasFile('image')) {
            $validated['image'] = $request->file('image')->store('programs/banners', 'public');
        }

        Program::create($validated);

        return redirect()->back()->with('success', 'Program created successfully.');
    }

    public function update(Request $request, $id)
    {
        $program = Program::findOrFail($id);
        $validated = $request->validate($this->rules());
        if ($request->has('school_id') && (string) ($validated['school_id'] ?? '') !== (string) ($program->school_id ?? '')
            && \App\Models\SchoolWorkspaceRecord::available()
            && \App\Models\SchoolWorkspaceRecord::where('program_id', $program->id)->exists()) {
            throw \Illuminate\Validation\ValidationException::withMessages(['school_id' => 'This programme has school management history. Keep its school association; archive it and create a separate programme if needed.']);
        }

        if ($request->hasFile('image')) {
            if ($program->image) {
                Storage::disk('public')->delete($program->image);
            }
            $validated['image'] = $request->file('image')->store('programs/banners', 'public');
        } else {
            unset($validated['image']);
        }

        // Partial-payload model: only write keys the request actually sent, so
        // a caller that posts a subset of the form can never blank out the
        // columns it didn't include. Explicitly-cleared fields still come
        // through (the key is present with a null/empty value).
        $patch = collect($validated)
            ->filter(fn ($v, $k) => $k === 'image' || $request->has($k))
            ->all();

        $program->update($patch);

        return redirect()->back()->with('success', 'Program updated successfully.');
    }

    public function destroy($id)
    {
        $program = Program::findOrFail($id);
        if (\App\Models\SchoolWorkspaceRecord::available() && \App\Models\SchoolWorkspaceRecord::where('program_id', $program->id)->exists()) {
            return back()->with('error', 'This programme has school management history. Archive it instead of deleting it.');
        }

        if ($program->image) {
            Storage::disk('public')->delete($program->image);
        }

        $program->delete();

        return redirect()->back()->with('success', 'Program deleted successfully.');
    }

    public function publicIndex()
    {
        return inertia('programs/ProgramsLevels', [
            'programs' => $this->fetchPublishedPrograms(),
            'activePromos' => PromoFeed::active(),
        ]);
    }

    public function feeGuideIndex()
    {
        return inertia('programs/FeeGuide', ['programs' => $this->fetchPublishedPrograms()]);
    }

    private function fetchPublishedPrograms()
    {
        $programs = Program::where('status', 'published')->orderBy('level')->latest()->get();
        $programs->each(fn ($p) => $this->appendImageUrl($p));

        return $programs;
    }

    /**
     * Full program detail as JSON for the staff-side ProgramDetailsModal (the
     * lead profile's Programs cards). Any authenticated staff member can read a
     * program's catalogue info — it's the same content shown on the public site.
     */
    public function detailsJson($id)
    {
        $program = Program::with('school:id,name')->findOrFail($id);

        return response()->json(['program' => $program->detailPayload()]);
    }

    public function publicShow(Program $program)
    {
        abort_if($program->status !== 'published', 404);
        $this->appendImageUrl($program);

        return inertia('programs/ProgramDetails', [
            'program' => $program,
            // Per-programme SEO metadata (unique title/description/canonical/OG +
            // breadcrumb JSON-LD), rendered server-side in app.blade.php. Takes
            // precedence over the config-driven lookup for this path.
            'seo' => app(\App\Support\Seo::class)->forProgram($program),
        ]);
    }
}
