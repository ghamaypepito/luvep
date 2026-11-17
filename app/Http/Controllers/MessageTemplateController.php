<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\MessageTemplate;
use App\Models\TemplateFolder;
use App\Models\User;
use App\Services\CommunicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * CRUD for message templates, shared by the admin area and every department
 * portal. The scope is driven by the ROUTE: /admin/message-templates is the
 * cross-department admin view (manages all templates + the shared set), while
 * /portal/<role>/email-templates is scoped to that one department for whoever
 * opens it — department staff or an admin browsing the portal. The portal:*
 * middleware on each route enforces who may reach it.
 */
class MessageTemplateController extends Controller
{
    /** Standard variables shown in the editor's reference panel. */
    private const STANDARD_VARIABLES = [
        ['name' => 'first_name', 'description' => "Lead's first name"],
        ['name' => 'last_name', 'description' => "Lead's last name"],
        ['name' => 'full_name', 'description' => "Lead's full name"],
        ['name' => 'email', 'description' => "Lead's email"],
        ['name' => 'phone', 'description' => "Lead's phone"],
        ['name' => 'visa_type', 'description' => "The case's visa type (INZ visa)"],
        ['name' => 'invoice_number', 'description' => "The case's latest invoice number (e.g. INV-0117)"],
        ['name' => 'password', 'description' => 'Portal password — only filled when credentials are generated (blank otherwise)'],
        ['name' => 'tracker_url', 'description' => 'Link to the lead /track/{code} page'],
        ['name' => 'engagement_url', 'description' => 'Scoped engagement signing link (only filled when sent from the engagement generator)'],
        ['name' => 'assigned_staff_name', 'description' => 'Assigned staff member, or "the EP team"'],
        ['name' => 'status', 'description' => 'Application status (passed when staff send an update)'],
        ['name' => 'status_detail', 'description' => 'Optional note describing the status change'],
        ['name' => 'event_name', 'description' => 'Event title (event_registration template)'],
        ['name' => 'event_date', 'description' => 'Event date (event_registration template)'],
        ['name' => 'event_time', 'description' => 'Event time (event_registration template)'],
        ['name' => 'event_location', 'description' => 'Event location (event_registration template)'],
    ];

    public function index(Request $request)
    {
        $ctx = $this->context($request);

        // Templates are a shared library — every portal (admin + each
        // department) sees the full set and may use/edit any of them.
        // Folders (also shared) let staff group them; grouping happens
        // client-side off each template's folder_id.
        return inertia($ctx['listComponent'], [
            'templates' => MessageTemplate::query()->orderBy('name')->get()->map(fn (MessageTemplate $t) => [
                'id' => $t->id, 'key' => $t->key, 'name' => $t->name,
                'department' => $t->department, 'channels' => $t->channels ?? [],
                'is_active' => $t->is_active, 'folder_id' => $t->folder_id,
                'updated_at' => optional($t->updated_at)?->toIso8601String(),
            ]),
            'folders' => TemplateFolder::orderBy('name')->get(['id', 'name', 'department']),
            'basePath' => $ctx['basePath'],
            'scopeLabel' => $ctx['scopeLabel'],
            // Drives the department tab strip + the "Move to department" picker
            // ('' = shared, then one entry per portal department).
            'departmentOptions' => $ctx['departmentOptions'],
        ]);
    }

    public function create(Request $request)
    {
        $ctx = $this->context($request);

        // An explicit Shared tab arrives as an empty query value.
        $defaultDepartment = $request->query->has('department') ? ($request->query('department') ?? '') : null;
        if (! in_array($defaultDepartment, array_merge([''], MessageTemplate::DEPARTMENTS), true)) {
            $defaultDepartment = null;
        }

        return inertia($ctx['editorComponent'], [
            'template' => null,
            'standardVariables' => self::STANDARD_VARIABLES,
            'basePath' => $ctx['basePath'],
            'departmentOptions' => $ctx['departmentOptions'],
            'brandingOptions' => $this->brandingOptions(),
            'fixedDepartment' => $defaultDepartment ?? $ctx['department'],
            'defaultChannel' => $request->query('channel'),
            // Carried through the New-template form so a template created from
            // inside a folder is saved into that folder.
            'defaultFolderId' => $request->query('folder_id'),
        ]);
    }

    public function show(Request $request, $id)
    {
        $ctx = $this->context($request);
        $template = MessageTemplate::findOrFail($id);
        $this->authorizeTemplate($ctx['department'], $template);

        // Expose public URLs for the optional branding images so the editor
        // can preview whatever is already saved.
        $template->banner_image_url = $template->banner_image ? Storage::disk('public')->url($template->banner_image) : null;
        $template->footer_image_url = $template->footer_image ? Storage::disk('public')->url($template->footer_image) : null;

        // The body is now rich HTML. Legacy templates were authored in Markdown
        // — convert them so they load formatted in the editor (and re-save as
        // HTML going forward).
        if ($template->email_body && ! preg_match('/<[a-z][\s\S]*>/i', $template->email_body)) {
            $template->email_body = Str::markdown($template->email_body);
        }

        return inertia($ctx['editorComponent'], [
            'template' => $template,
            'standardVariables' => self::STANDARD_VARIABLES,
            'basePath' => $ctx['basePath'],
            'departmentOptions' => $ctx['departmentOptions'],
            'brandingOptions' => $this->brandingOptions(),
            'fixedDepartment' => $ctx['department'],
            'defaultChannel' => null,
        ]);
    }

    /**
     * Open the editor pre-filled from an existing template so a whole set can be
     * cloned for another department without re-authoring each one. Everything
     * copies EXCEPT the key — it's left blank so the user assigns a fresh,
     * unique one (nothing is written until they save through store()). Uploaded
     * banner/footer images are not carried (they're per-template files); the
     * branding preset is, which drives the artwork for the common case.
     */
    public function duplicate(Request $request, $id)
    {
        $ctx = $this->context($request);
        $source = MessageTemplate::findOrFail($id);
        $this->authorizeTemplate($ctx['department'], $source);

        // A template-shaped payload with no id + blank key → the editor treats it
        // as a create (POST), not an update.
        $emailBody = $source->email_body;
        if ($emailBody && ! preg_match('/<[a-z][\s\S]*>/i', $emailBody)) {
            $emailBody = Str::markdown($emailBody);
        }

        $prefill = [
            'id' => null,
            'key' => '',
            'name' => $source->name.' (copy)',
            'description' => $source->description,
            'department' => $source->department,
            'folder_id' => $source->folder_id,
            'channels' => $source->channels ?? [],
            'email_subject' => $source->email_subject,
            'email_body' => $emailBody,
            'design_json' => $source->design_json,
            'from_email' => $source->from_email,
            'from_name' => $source->from_name,
            'reply_to_email' => $source->reply_to_email,
            'reply_to_name' => $source->reply_to_name,
            'branding' => $source->branding,
            'to_extra' => $source->to_extra,
            'cc' => $source->cc,
            'bcc' => $source->bcc,
            'sms_body' => $source->sms_body,
            'is_active' => $source->is_active,
        ];

        return inertia($ctx['editorComponent'], [
            'template' => $prefill,
            'standardVariables' => self::STANDARD_VARIABLES,
            'basePath' => $ctx['basePath'],
            'departmentOptions' => $ctx['departmentOptions'],
            'brandingOptions' => $this->brandingOptions(),
            'fixedDepartment' => $ctx['department'],
            'defaultChannel' => null,
            'duplicatedFrom' => $source->name,
        ]);
    }

    public function store(Request $request)
    {
        $ctx = $this->context($request);
        $department = $this->resolveStoreDepartment($request, $ctx['department']);

        $data = $request->validate([
            'key' => [
                'required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('message_templates', 'key')->where(fn ($q) => $q->where('department', $department)),
            ],
            'folder_id' => ['nullable', 'integer', Rule::exists('template_folders', 'id')->where('department', $department)],
            ...$this->bodyRules(),
            ...$this->imageRules(),
        ], [
            'key.regex' => 'Key must be lowercase letters, numbers and underscores only.',
            'folder_id.exists' => 'Choose a folder in the selected department, or create the template outside a folder.',
        ]);

        $this->applyImages($request, $data, null);
        $data['email_body'] = $this->sanitizeBody($data['email_body'] ?? null);
        $data['department'] = $department;
        $data['created_by'] = $request->user()->id;
        $template = MessageTemplate::create($data);

        return redirect()->to($ctx['basePath'].'/'.$template->id)->with('success', 'Template created.');
    }

    public function update(Request $request, $id)
    {
        $ctx = $this->context($request);
        $template = MessageTemplate::findOrFail($id);
        $this->authorizeTemplate($ctx['department'], $template);

        // A keyless draft (from a folder duplicate) is being finalised — its key
        // is set now. An already-keyed template keeps its key immutable (code
        // references it). Department is editable from every portal.
        $isDraft = empty($template->key);
        $department = $this->resolveStoreDepartment($request, $ctx['department']);

        $rules = [...$this->bodyRules(), ...$this->imageRules()];
        if ($isDraft) {
            $rules['key'] = [
                'required', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('message_templates', 'key')
                    ->where(fn ($q) => $q->where('department', $department))
                    ->ignore($template->id),
            ];
        }
        $data = $request->validate($rules, ['key.regex' => 'Key must be lowercase letters, numbers and underscores only.']);

        if (! $isDraft) {
            // Guard key+department uniqueness when moving an existing template.
            $clash = MessageTemplate::where('key', $template->key)
                ->where('department', $department)
                ->where('id', '!=', $template->id)
                ->exists();
            if ($clash) {
                return back()->withErrors([
                    'department' => "A '{$template->key}' template already exists in that scope.",
                ]);
            }
            unset($data['key']); // never change an existing key
        }
        $data['department'] = $department;
        if ($template->folder_id && $template->department !== $department && $template->folder?->department !== $department) {
            $data['folder_id'] = null;
        }

        $this->applyImages($request, $data, $template);
        $data['email_body'] = $this->sanitizeBody($data['email_body'] ?? null);
        $template->update($data);

        return back()->with('success', 'Template saved.');
    }

    public function destroy(Request $request, $id)
    {
        $ctx = $this->context($request);
        $template = MessageTemplate::findOrFail($id);
        $this->authorizeTemplate($ctx['department'], $template);
        $template->delete();

        return redirect()->to($ctx['basePath'])->with('success', 'Template removed.');
    }

    /**
     * Bulk-delete the selected templates (soft delete, like the single destroy).
     * Backs the "Delete" action that only appears once templates are selected.
     */
    public function destroyMany(Request $request)
    {
        $this->context($request);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:message_templates,id'],
        ]);

        $count = MessageTemplate::whereIn('id', $data['ids'])->count();
        MessageTemplate::whereIn('id', $data['ids'])->delete();

        return back()->with('success', "{$count} template".($count === 1 ? '' : 's').' deleted.');
    }

    /**
     * Create a folder inside a department tab ('' = the Shared tab). Optionally
     * move a set of templates into it in the same step — the "select templates →
     * group into a new folder" flow. Members inherit the folder's department so
     * a folder never mixes departments (any that would clash on key stay out).
     */
    public function storeFolder(Request $request)
    {
        $this->context($request); // gates access via the route's portal:* middleware

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'department' => ['nullable', Rule::in(array_merge([''], MessageTemplate::DEPARTMENTS))],
            'template_ids' => ['array'],
            'template_ids.*' => ['integer', 'exists:message_templates,id'],
        ]);

        $department = (string) ($data['department'] ?? '');

        $folder = TemplateFolder::create([
            'name' => $data['name'],
            'department' => $department,
            'created_by' => $request->user()->id,
        ]);

        $skipped = 0;
        if (! empty($data['template_ids'])) {
            $templates = MessageTemplate::whereIn('id', $data['template_ids'])->get();
            [, $skippedIds] = $this->applyDepartmentToTemplates($templates, $department);
            $skipped = count($skippedIds);
            // Only the templates that could take the folder's department join it.
            $movableIds = array_diff($data['template_ids'], $skippedIds);
            if (! empty($movableIds)) {
                MessageTemplate::whereIn('id', $movableIds)->update(['folder_id' => $folder->id]);
            }
        }

        $msg = 'Folder created.';
        if ($skipped > 0) {
            $msg .= " {$skipped} template(s) were left out — the same key already exists in that department.";
        }

        return back()->with('success', $msg);
    }

    public function updateFolder(Request $request, $id)
    {
        $this->context($request);
        $folder = TemplateFolder::findOrFail($id);

        $folder->update($request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]));

        return back()->with('success', 'Folder renamed.');
    }

    /**
     * Move a whole folder into a department tab. Its templates follow so the
     * folder stays single-department; any template whose key already exists in
     * the target department is ejected to the root instead of clashing.
     */
    public function moveFolderDepartment(Request $request, $id)
    {
        $this->context($request);
        $folder = TemplateFolder::findOrFail($id);

        $data = $request->validate([
            'department' => ['nullable', Rule::in(array_merge([''], MessageTemplate::DEPARTMENTS))],
        ]);
        $department = (string) ($data['department'] ?? '');

        [, $skippedIds] = $this->applyDepartmentToTemplates($folder->templates()->get(), $department);
        if (! empty($skippedIds)) {
            MessageTemplate::whereIn('id', $skippedIds)->update(['folder_id' => null]);
        }
        $folder->update(['department' => $department]);

        $label = $department === '' ? 'Shared' : ucfirst($department);
        $msg = "Folder moved to {$label}.";
        if (! empty($skippedIds)) {
            $msg .= ' '.count($skippedIds).' template(s) with a clashing key were moved out to the root instead.';
        }

        return back()->with('success', $msg);
    }

    /**
     * Clone a folder AND every template inside it into another department in one
     * step — so a whole set can be reused for another team without rebuilding the
     * folder and duplicating each template by hand. The originals are untouched:
     * a brand-new folder and brand-new template rows are created.
     *
     * Each cloned template is a KEYLESS DRAFT — its key is left blank (NULL) and
     * it's created inactive, so you assign a fresh, department-specific key
     * before it can be used (exactly like the single-template duplicate leaves
     * the key blank). This also sidesteps any key clash with existing templates.
     * Uploaded banner/footer images aren't copied (per-template files — the
     * branding preset carries the look).
     */
    public function duplicateFolder(Request $request, $id)
    {
        $this->context($request);
        $source = TemplateFolder::with('templates')->findOrFail($id);

        $data = $request->validate([
            'department' => ['nullable', Rule::in(array_merge([''], MessageTemplate::DEPARTMENTS))],
        ]);
        $department = (string) ($data['department'] ?? '');
        $sameDept = $source->department === $department;

        $copied = 0;
        DB::transaction(function () use ($source, $department, $sameDept, $request, &$copied) {
            $newFolder = TemplateFolder::create([
                'name' => $sameDept ? $source->name.' (copy)' : $source->name,
                'department' => $department,
                'created_by' => $request->user()->id,
            ]);

            foreach ($source->templates as $t) {
                MessageTemplate::create([
                    'key' => null,          // keyless draft — user sets the key
                    'department' => $department,
                    'folder_id' => $newFolder->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'channels' => $t->channels,
                    'email_subject' => $t->email_subject,
                    'email_body' => $t->email_body,
                    'design_json' => $t->design_json,
                    'from_email' => $t->from_email,
                    'from_name' => $t->from_name,
                    'reply_to_email' => $t->reply_to_email,
                    'reply_to_name' => $t->reply_to_name,
                    'branding' => $t->branding,
                    'to_extra' => $t->to_extra,
                    'cc' => $t->cc,
                    'bcc' => $t->bcc,
                    'sms_body' => $t->sms_body,
                    'is_active' => false,   // can't be used until it has a key
                    'created_by' => $request->user()->id,
                    // banner_image / footer_image intentionally not copied — two
                    // rows sharing one stored file would break when one edits it.
                ]);
                $copied++;
            }
        });

        $label = $department === '' ? 'Shared' : ucfirst($department);
        $msg = "Folder duplicated to {$label} — {$copied} draft template".($copied === 1 ? '' : 's')
            .' created. Open each to set its key before use.';

        return back()->with('success', $msg);
    }

    /**
     * Set the department on a set of templates, skipping any whose (key,
     * department) pair would collide with an existing row (the unique index on
     * message_templates). Returns [movedCount, array $skippedIds].
     */
    private function applyDepartmentToTemplates(\Illuminate\Support\Collection $templates, string $department): array
    {
        $moved = 0;
        $skippedIds = [];
        foreach ($templates as $template) {
            if ($template->department === $department) {
                continue; // already there
            }
            $clash = MessageTemplate::where('key', $template->key)
                ->where('department', $department)
                ->where('id', '!=', $template->id)
                ->exists();
            if ($clash) {
                $skippedIds[] = $template->id;

                continue;
            }
            $template->update(['department' => $department]);
            $moved++;
        }

        return [$moved, $skippedIds];
    }

    /**
     * Delete a folder. Its templates are kept — folder_id is nulled by the
     * FK's nullOnDelete, so they return to the ungrouped root.
     */
    public function destroyFolder(Request $request, $id)
    {
        $this->context($request);
        TemplateFolder::findOrFail($id)->delete();

        return back()->with('success', 'Folder deleted. Its templates moved to the root.');
    }

    /**
     * Move templates into a folder, or out to the root when folder_id is null.
     * Backs the checkbox "Move to folder" action and per-row remove.
     */
    public function moveTemplates(Request $request)
    {
        $this->context($request);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:message_templates,id'],
            'folder_id' => ['nullable', 'integer', 'exists:template_folders,id'],
        ]);

        // Moving to the root just ungroups — department is untouched.
        if (empty($data['folder_id'])) {
            MessageTemplate::whereIn('id', $data['ids'])->update(['folder_id' => null]);

            return back()->with('success', 'Templates moved.');
        }

        // Moving into a folder: members inherit the folder's department so the
        // folder stays single-department. Key clashes are left out.
        $folder = TemplateFolder::findOrFail($data['folder_id']);
        [, $skippedIds] = $this->applyDepartmentToTemplates(MessageTemplate::whereIn('id', $data['ids'])->get(), $folder->department);
        $movableIds = array_diff($data['ids'], $skippedIds);
        if (! empty($movableIds)) {
            MessageTemplate::whereIn('id', $movableIds)->update(['folder_id' => $folder->id]);
        }

        $msg = 'Templates moved.';
        if (! empty($skippedIds)) {
            $msg .= ' '.count($skippedIds).' left out — the same key already exists in that department.';
        }

        return back()->with('success', $msg);
    }

    /**
     * Bulk-move the selected templates into a department scope ('' = shared).
     * Backs the "Move to department" action behind the admin department tabs.
     * Skips any template whose (key, target department) pair already exists on
     * another row so the key+department uniqueness invariant is preserved.
     */
    public function moveDepartment(Request $request)
    {
        $this->context($request);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:message_templates,id'],
            'department' => ['nullable', Rule::in(array_merge([''], MessageTemplate::DEPARTMENTS))],
        ]);

        $department = (string) ($data['department'] ?? '');
        $templates = MessageTemplate::whereIn('id', $data['ids'])->get();

        [$moved, $skippedIds] = $this->applyDepartmentToTemplates($templates, $department);
        $skipped = count($skippedIds);

        $label = $department === '' ? 'Shared' : ucfirst($department);
        $msg = "Moved {$moved} template".($moved === 1 ? '' : 's')." to {$label}.";
        if ($skipped > 0) {
            $msg .= " {$skipped} skipped — a template with the same key already exists there.";
        }

        return back()->with($moved === 0 && $skipped > 0 ? 'error' : 'success', $msg);
    }

    /**
     * Send a test of this template to a staff-supplied email/phone (or the
     * current user), using a synthetic sample lead for the variables.
     */
    public function sendTest(Request $request, $id, CommunicationService $comms)
    {
        $ctx = $this->context($request);
        $template = MessageTemplate::findOrFail($id);
        $this->authorizeTemplate($ctx['department'], $template);

        $validated = $request->validate([
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:40',
        ]);

        if (! $template->is_active) {
            return back()->with('error', 'Activate the template before sending a test.');
        }

        $user = $request->user();
        $sample = new Lead([
            'first_name' => $user->name,
            'last_name' => '',
            'email' => $validated['email'] ?? $user->email,
            'phone' => $validated['phone'] ?? null,
        ]);
        $sample->tracking_code = 'SAMPLE-CODE';

        // Sample values cover every templated variable so any template renders.
        $comms->sendTemplate($template, $sample, [
            'document_name' => 'Sample Document.pdf',
            'reason' => 'This is a sample reason.',
            'status' => 'Under Review',
            'status_detail' => 'Your application is currently being reviewed by our team.',
        ]);

        return back()->with('success', 'Test message sent.');
    }

    /**
     * Resolve the acting view context (component names, base path, scoped
     * department, and the admin-only department list) from the ROUTE, not the
     * user. The /portal/<role>/... routes are scoped to that department for
     * everyone (including admins browsing the portal); /admin/... is the
     * cross-department admin view. Access itself is gated by the portal:*
     * middleware on each route.
     *
     * @return array{department: ?string, basePath: string, listComponent: string, editorComponent: string, departmentOptions: ?array, scopeLabel: string}
     */
    private function context(Request $request): array
    {
        $name = (string) $request->route()?->getName();
        $department = null;
        if (str_starts_with($name, 'portal.')) {
            $candidate = explode('.', $name)[1] ?? null;
            if (in_array($candidate, MessageTemplate::DEPARTMENTS, true)) {
                $department = $candidate;
            }
        }

        if ($department === null) {
            return [
                'department' => null,
                'basePath' => '/admin/message-templates',
                'listComponent' => 'admin/MessageTemplates',
                'editorComponent' => 'admin/MessageTemplateEditor',
                'departmentOptions' => $this->departmentOptions(),
                'scopeLabel' => 'All departments',
            ];
        }

        return [
            'department' => $department,
            'basePath' => "/portal/{$department}/email-templates",
            'listComponent' => "portal/{$department}/EmailTemplates",
            'editorComponent' => "portal/{$department}/EmailTemplateEditor",
            // Department picker is now available in every portal, not just the
            // admin area — staff can scope a template to shared or any team.
            'departmentOptions' => $this->departmentOptions(),
            'scopeLabel' => ucfirst($department),
        ];
    }

    /**
     * Department for a new template: forced to their own for staff, chosen for
     * admins ('' = shared/global). Never null — '' is the shared sentinel.
     */
    private function resolveStoreDepartment(Request $request, ?string $actingDepartment): string
    {
        $request->validate([
            'department' => ['nullable', Rule::in(array_merge([''], MessageTemplate::DEPARTMENTS))],
        ]);

        // The selector now shows in every portal, so honour an explicitly
        // submitted department; otherwise default to the acting portal's own
        // department (or shared in the admin area).
        if ($request->has('department')) {
            return (string) ($request->input('department') ?: '');
        }

        return (string) ($actingDepartment ?: '');
    }

    /**
     * Templates are a shared library across all portals — any staff member
     * (admin or department) may view, edit, and delete any template. Kept as a
     * hook so the call sites stay unchanged if per-department rules return.
     */
    private function authorizeTemplate(?string $actingDepartment, MessageTemplate $template): void
    {
        // no-op — fully shared
    }

    /**
     * Email branding presets for the editor's picker (Default + each portal),
     * each carrying the banner + CTA preview URLs that would actually be used
     * (a portal without its own asset resolves to the default artwork), so the
     * editor can show a live preview of the selected branding.
     */
    private function brandingOptions(): array
    {
        return collect(config('email_branding', []))
            ->map(function ($cfg, $key) {
                // Resolver prefers an admin-uploaded image, then the file asset,
                // then the default — same order the email itself uses.
                $assets = \App\Models\EmailBranding::resolveAssets($key);

                return [
                    'value' => $key,
                    'label' => $cfg['label'] ?? ucfirst($key),
                    'banner_url' => $assets['bannerUrl'],
                    'footer_url' => $assets['footerUrl'],
                ];
            })
            ->values()->all();
    }

    /** Admin-only department picker: a blank shared option plus each portal. */
    private function departmentOptions(): array
    {
        return array_merge(
            [['value' => '', 'label' => 'Shared (all departments)']],
            array_map(fn ($d) => ['value' => $d, 'label' => ucfirst($d)], MessageTemplate::DEPARTMENTS),
        );
    }

    private function bodyRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:1000'],
            'channels' => ['array'],
            'channels.*' => [Rule::in(MessageTemplate::CHANNELS)],
            'email_subject' => ['nullable', 'string', 'max:255'],
            // Optional per-template sender (must be a verified address in the
            // mail provider). Null = the app default MAIL_FROM.
            'from_email' => ['nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:120'],
            // Optional per-template Reply-To — where client replies land. Wins
            // over the central config inbox. Null = fall back to that inbox.
            'reply_to_email' => ['nullable', 'email', 'max:255'],
            'reply_to_name' => ['nullable', 'string', 'max:120'],
            // Per-portal banner/CTA preset (config/email_branding.php).
            'branding' => ['nullable', Rule::in(array_keys(config('email_branding', ['default' => []])))],
            // Comma-separated addresses copied on every send.
            'to_extra' => ['nullable', 'string', 'max:1000'],
            'cc' => ['nullable', 'string', 'max:1000'],
            'bcc' => ['nullable', 'string', 'max:1000'],
            // Unlayer design document for builder-made templates (nullable = a
            // plain-text template edited without the visual builder).
            'design_json' => ['nullable', 'array'],
            // Rich HTML from the editor is more verbose than the old Markdown.
            'email_body' => ['nullable', 'string', 'max:65000'],
            'sms_body' => ['nullable', 'string', 'max:1600'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * Strip dangerous elements/attributes from the rich-text body. The editor
     * only emits safe HTML and authors are trusted staff, but this is a cheap
     * defense-in-depth pass before the HTML is stored and later emailed.
     */
    private function sanitizeBody(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        // <style> is intentionally allowed (the visual email builder emits a
        // scoped style block); it cannot execute JS in modern mail clients. We
        // still strip active content and neutralise the few CSS attack vectors.
        $html = preg_replace('#<(script|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html);
        $html = preg_replace('#<(script|iframe|object|embed)\b[^>]*/?>#i', '', $html);
        $html = preg_replace('#\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
        $html = preg_replace('#(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1="#"', $html);
        $html = preg_replace('#expression\s*\(#i', 'expr_(', $html);          // legacy IE CSS exec
        $html = preg_replace('#@import\b#i', '/* import */', $html);          // no remote CSS pulls

        return $html;
    }

    /**
     * Image upload target for the visual email builder (GrapesJS asset manager).
     * Stores on the PUBLIC disk and returns the GrapesJS-expected shape so the
     * uploaded image drops straight onto the canvas with a hosted URL.
     */
    public function uploadEmailImage(Request $request)
    {
        $this->context($request); // access gated by the route's portal:* middleware

        $request->validate([
            'files' => ['required', 'array', 'max:10'],
            'files.*' => ['image', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
        ]);

        $data = [];
        foreach ($request->file('files', []) as $file) {
            $path = $file->store('email-assets', 'public');
            $data[] = ['src' => Storage::disk('public')->url($path)];
        }

        return response()->json(['data' => $data]);
    }

    /** Optional email-shell branding images (banner header + footer CTA). */
    private function imageRules(): array
    {
        return [
            'banner_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:4096'],
            'footer_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:4096'],
            'remove_banner' => ['nullable', 'boolean'],
            'remove_footer' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Resolve the two optional branding images into stored paths on $data.
     * A new upload replaces (and deletes) the old file; a `remove_*` flag
     * clears it; otherwise the column is left untouched. The transient
     * validation keys never reach the model.
     */
    private function applyImages(Request $request, array &$data, ?MessageTemplate $existing): void
    {
        $map = ['banner_image' => 'templates/banners', 'footer_image' => 'templates/footers'];

        foreach ($map as $field => $dir) {
            $removeFlag = 'remove_'.str_replace('_image', '', $field);

            if ($request->hasFile($field)) {
                if ($existing?->{$field}) {
                    Storage::disk('public')->delete($existing->{$field});
                }
                $data[$field] = $request->file($field)->store($dir, 'public');
            } elseif ($request->boolean($removeFlag)) {
                if ($existing?->{$field}) {
                    Storage::disk('public')->delete($existing->{$field});
                }
                $data[$field] = null;
            } else {
                unset($data[$field]);
            }

            unset($data[$removeFlag]);
        }
    }
}
