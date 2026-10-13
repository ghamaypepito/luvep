<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\UploadValidation;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /** super-admin + admin + every department-portal role + the immigration
     *  manager/adviser tiers (so an admin can actually assign them and manage
     *  their IAA licence — Build 12 fast-follow). */
    private function roleValues(): array
    {
        return array_merge(
            [User::ROLE_SUPER_ADMIN, User::ROLE_ADMIN],
            User::PORTAL_ROLES,
            User::IMMIGRATION_ROLES,
        );
    }

    public function index()
    {
        // Organization tab is staff only — external Lead Portal accounts
        // ('lead' / 'revoked_lead') belong on the Leads tab, not in the
        // staff directory.
        $users = User::whereNotIn('role', ['lead', 'revoked_lead'])
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'phone', 'location', 'role', 'is_active', 'avatar_path', 'created_at',
                // Sub-agent → recruiting agent link; the edit modal pre-fills it.
                'parent_agent_id',
                // IAA licence — admin-managed (Build 12 fast-follow); the edit
                // modal pre-fills from these.
                'iaa_licence_number', 'iaa_licence_type', 'iaa_licence_expiry', 'iaa_licence_verified_at']);

        // Soft-deleted staff accounts — shown in the "Deleted" view for restore.
        $deletedUsers = User::onlyTrashed()
            ->whereNotIn('role', ['lead', 'revoked_lead'])
            ->orderByDesc('deleted_at')
            ->get(['id', 'name', 'email', 'role', 'avatar_path', 'created_at', 'deleted_at']);

        // Cross-link rolls for the User Management tabs. Each list is a
        // thin name + email + stage projection that the frontend renders
        // into a sortable table with a drill-in link to /admin/leads/{id}.
        // Capped at 500 rows per tab; if a tenant outgrows that, we'll
        // switch to paginated partial reloads per tab.
        $leadCols = ['id', 'lead_id', 'first_name', 'last_name', 'email', 'phone', 'stage', 'status', 'created_at'];

        $leads = \App\Models\Lead::inLeadPipeline()
            ->orderByDesc('created_at')
            ->limit(500)
            ->get($leadCols);

        $students = \App\Models\Lead::where('is_student', true)
            ->orderByDesc('created_at')
            ->limit(500)
            ->get(array_merge($leadCols, ['education_stage', 'english_stage', 'student_converted_at']));

        $cases = \App\Models\Lead::immigrationCase()
            ->orderByDesc('created_at')
            ->limit(500)
            ->get(array_merge($leadCols, ['immigration_stage', 'inz_visa_type', 'immigration_converted_at']));

        // Recruiting agents a sub-agent can be linked to (parent picker).
        $agents = User::where('role', 'agent')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'referral_code']);

        return inertia('admin/Users', [
            'users' => $users,
            'deletedUsers' => $deletedUsers,
            'agents' => $agents,
            'roles' => $this->roleValues(),
            'leads' => $leads,
            'students' => $students,
            'cases' => $cases,
        ]);
    }

    public function store(Request $request)
    {
        // Agents recruit on the ground — capture their contact + location.
        $contactRule = $request->input('role') === 'agent' ? 'required' : 'nullable';

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => [$contactRule, 'string', 'max:60'],
            'location' => [$contactRule, 'string', 'max:255'],
            'role' => ['required', Rule::in($this->roleValues())],
            // A sub-agent must be linked to the recruiting agent they work under.
            'parent_agent_id' => [
                $request->input('role') === User::ROLE_SUB_AGENT ? 'required' : 'nullable',
                Rule::exists('users', 'id')->where('role', 'agent'),
            ],
            'password' => ['required', \Illuminate\Validation\Rules\Password::defaults()],
            'avatar' => 'nullable|'.UploadValidation::image(),
            // IAA licence — admin-set + audited (Build 12 fast-follow). The
            // expiry is load-bearing (it gates advice), so it is required
            // whenever a number is recorded; a number with no expiry would
            // silently fail the gate.
            'iaa_licence_number' => 'nullable|string|max:60',
            'iaa_licence_type' => 'nullable|string|in:Full,Provisional,Limited',
            'iaa_licence_expiry' => 'nullable|date|required_with:iaa_licence_number',
            'iaa_licence_verified_at' => 'nullable|date',
        ], [
            'iaa_licence_expiry.required_with' => 'Set the licence expiry date whenever a licence number is recorded — the expiry is what gates advice.',
        ]);

        // Only a super admin may create another super admin.
        if ($validated['role'] === User::ROLE_SUPER_ADMIN && optional($request->user())->role !== User::ROLE_SUPER_ADMIN) {
            return back()->withErrors(['role' => 'Only a super admin can assign the Super Admin role.']);
        }

        // Only a sub-agent carries a parent agent link — clear it for any
        // other role so a stray value can't leak through.
        if ($validated['role'] !== User::ROLE_SUB_AGENT) {
            $validated['parent_agent_id'] = null;
        }

        // The 'password' => 'hashed' cast on the model hashes this on save.
        $user = User::create(collect($validated)->except('avatar')->all());

        if ($request->hasFile('avatar')) {
            $this->saveAvatar($user, $request->file('avatar'));
        }

        ActivityLog::record('user.created', [
            'description' => "Created user {$user->name} ({$user->email}) — {$user->role}",
            'properties' => ['target_id' => $user->id, 'target_email' => $user->email, 'target_role' => $user->role],
        ]);

        return back()->with('success', 'User created successfully.');
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);

        $contactRule = $request->input('role') === 'agent' ? 'required' : 'nullable';

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => [$contactRule, 'string', 'max:60'],
            'location' => [$contactRule, 'string', 'max:255'],
            'role' => ['required', Rule::in($this->roleValues())],
            // A sub-agent must be linked to the recruiting agent they work under.
            'parent_agent_id' => [
                $request->input('role') === User::ROLE_SUB_AGENT ? 'required' : 'nullable',
                Rule::exists('users', 'id')->where('role', 'agent'),
            ],
            'password' => ['nullable', \Illuminate\Validation\Rules\Password::defaults()],
            'avatar' => 'nullable|'.UploadValidation::image(),
            // IAA licence — admin-set + audited (Build 12 fast-follow). Expiry
            // required whenever a number is present so the advice gate is never
            // left with a number but no expiry.
            'iaa_licence_number' => 'nullable|string|max:60',
            'iaa_licence_type' => 'nullable|string|in:Full,Provisional,Limited',
            'iaa_licence_expiry' => 'nullable|date|required_with:iaa_licence_number',
            'iaa_licence_verified_at' => 'nullable|date',
        ], [
            'iaa_licence_expiry.required_with' => 'Set the licence expiry date whenever a licence number is recorded — the expiry is what gates advice.',
        ]);

        // Don't let anyone change their own role — avoids locking yourself out
        // (e.g. a super-admin demoting themselves and losing the super surface).
        if ($user->is($request->user()) && $validated['role'] !== $user->role) {
            return back()->withErrors(['role' => 'You cannot change your own role.']);
        }

        // Only a super admin may grant the Super Admin role (unless the target
        // already has it, so admins can still edit their other fields).
        if ($validated['role'] === User::ROLE_SUPER_ADMIN
            && $user->role !== User::ROLE_SUPER_ADMIN
            && optional($request->user())->role !== User::ROLE_SUPER_ADMIN) {
            return back()->withErrors(['role' => 'Only a super admin can assign the Super Admin role.']);
        }

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->phone = $validated['phone'] ?? null;
        $user->location = $validated['location'] ?? null;
        $user->role = $validated['role'];
        // Sub-agents are linked to one recruiting agent; every other role
        // clears the link.
        $user->parent_agent_id = $validated['role'] === User::ROLE_SUB_AGENT
            ? ($validated['parent_agent_id'] ?? null)
            : null;
        // Licence fields — nulled out when cleared, so revoking a licence is a
        // real admin action captured in the audit's `changed` list.
        $user->iaa_licence_number = $validated['iaa_licence_number'] ?? null;
        $user->iaa_licence_type = $validated['iaa_licence_type'] ?? null;
        $user->iaa_licence_expiry = $validated['iaa_licence_expiry'] ?? null;
        $user->iaa_licence_verified_at = $validated['iaa_licence_verified_at'] ?? null;
        $passwordChanged = ! empty($validated['password']);
        if ($passwordChanged) {
            $user->password = $validated['password'];
        }
        $changed = array_keys($user->getDirty());
        $user->save();

        if ($request->hasFile('avatar')) {
            $this->saveAvatar($user, $request->file('avatar'));
        }

        ActivityLog::record('user.updated', [
            'description' => "Updated user {$user->name} ({$user->email})".($passwordChanged ? ' — password reset' : ''),
            'properties' => ['target_id' => $user->id, 'target_email' => $user->email, 'target_role' => $user->role, 'changed' => $changed],
        ]);

        return back()->with('success', 'User updated successfully.');
    }

    public function destroy(Request $request, $id)
    {
        $user = User::findOrFail($id);

        if ($user->is($request->user())) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }

        $snapshot = ['target_id' => $user->id, 'target_name' => $user->name, 'target_email' => $user->email, 'target_role' => $user->role];

        $user->delete();

        ActivityLog::record('user.deleted', [
            'description' => "Deleted user {$snapshot['target_name']} ({$snapshot['target_email']})",
            'properties' => $snapshot,
        ]);

        return back()->with('success', 'User deleted successfully.');
    }

    /** Restore a soft-deleted user. */
    public function restore(Request $request, $id)
    {
        $user = User::onlyTrashed()->findOrFail($id);
        $user->restore();
        ActivityLog::record('user.restored', [
            'description' => "Restored user {$user->name} ({$user->email})",
            'properties' => ['target_id' => $user->id, 'target_name' => $user->name, 'target_email' => $user->email, 'target_role' => $user->role],
        ]);

        return back()->with('success', 'User restored successfully.');
    }

    /** Activate / deactivate a user account (reversible suspension). */
    public function toggleActive(Request $request, $id)
    {
        $user = User::findOrFail($id);
        if ($user->is($request->user())) {
            return back()->withErrors(['user' => 'You cannot deactivate your own account.']);
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        ActivityLog::record($user->is_active ? 'user.activated' : 'user.deactivated', [
            'description' => ($user->is_active ? 'Activated' : 'Deactivated')." user {$user->name} ({$user->email})",
            'properties' => ['target_id' => $user->id, 'target_name' => $user->name, 'target_email' => $user->email, 'target_role' => $user->role],
        ]);

        return back()->with('success', $user->is_active ? 'User activated.' : 'User deactivated.');
    }

    /**
     * Save the signed-in staff member's e-signature (drawn/typed canvas data or
     * an uploaded image). Stored on the user and reused anywhere their signature
     * is rendered (e.g. the accommodation agreement's Exalt admin signatory).
     */
    public function saveSignature(Request $request)
    {
        $request->validate([
            'signature_image' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
            'signature_data' => 'nullable|string',
        ]);
        $me = $request->user();
        try {
            $binary = null;
            $ext = 'png';
            if ($request->hasFile('signature_image')) {
                $file = $request->file('signature_image');
                $binary = file_get_contents($file->getRealPath());
                $ext = strtolower($file->getClientOriginalExtension()) === 'jpg' ? 'jpeg' : strtolower($file->getClientOriginalExtension());
                $ext = in_array($ext, ['png', 'jpeg'], true) ? $ext : 'png';
            } elseif ($request->filled('signature_data')) {
                $data = $request->input('signature_data');
                if (preg_match('/^data:image\/(png|jpe?g);base64,/', $data, $m)) {
                    $ext = str_starts_with($m[1], 'jp') ? 'jpeg' : 'png';
                    $data = substr($data, strpos($data, ',') + 1);
                }
                $binary = base64_decode(str_replace(' ', '+', $data), true);
            }
            if (! $binary) {
                return back()->withErrors(['error' => 'No signature provided.']);
            }
            if ($me->signature_path) {
                Storage::disk('local')->delete($me->signature_path);
            }
            $path = "signatures/user-{$me->id}-".Str::random(8).".{$ext}";
            Storage::disk('local')->put($path, $binary);
            $me->forceFill(['signature_path' => $path, 'signature_updated_at' => now()])->save();

            return back()->with('success', 'Signature saved.');
        } catch (\Throwable $e) {
            Log::error('Signature save failed', ['user_id' => $me?->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not save the signature.']);
        }
    }

    /** Remove the signed-in staff member's stored e-signature. */
    public function deleteSignature(Request $request)
    {
        $me = $request->user();
        try {
            if ($me->signature_path) {
                Storage::disk('local')->delete($me->signature_path);
            }
            $me->forceFill(['signature_path' => null, 'signature_updated_at' => null])->save();

            return back()->with('success', 'Signature removed.');
        } catch (\Throwable $e) {
            Log::error('Signature delete failed', ['user_id' => $me?->id, 'error' => $e->getMessage()]);

            return back()->withErrors(['error' => 'Could not remove the signature.']);
        }
    }

    // ─── Profile avatar ───────────────────────────────────────────────────

    /**
     * Store an uploaded avatar for the given user — downscaled to 256×256 and
     * saved on the public disk at avatars/{id}.{ext}; replaces any previous
     * file. Shared by the admin create/edit-user flow and self-service upload.
     */
    private function saveAvatar(User $user, $file): void
    {
        $ext = match (strtolower((string) ($file->extension() ?: 'jpg'))) {
            'png' => 'png',
            'webp' => 'webp',
            default => 'jpg',
        };
        $path = "avatars/{$user->id}.{$ext}";

        Storage::disk('public')->put($path, $this->downscaleAvatar($file, $ext));

        if ($user->avatar_path && $user->avatar_path !== $path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->forceFill(['avatar_path' => $path])->save();
    }

    /**
     * Upload (or replace) the current user's avatar. Image is downscaled to
     * fit 256×256 and stored on the public disk at avatars/{id}.{ext}.
     */
    public function uploadAvatar(Request $request)
    {
        $request->validate(['avatar' => 'required|'.UploadValidation::image()]);

        $user = $request->user();
        $file = $request->file('avatar');
        $ext = match (strtolower((string) ($file->extension() ?: 'jpg'))) {
            'png' => 'png',
            'webp' => 'webp',
            default => 'jpg',
        };
        $path = "avatars/{$user->id}.{$ext}";

        Storage::disk('public')->put($path, $this->downscaleAvatar($file, $ext));

        // Remove a previous avatar with a different extension.
        if ($user->avatar_path && $user->avatar_path !== $path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->forceFill(['avatar_path' => $path])->save();

        return back()->with('success', 'Profile photo updated.');
    }

    public function deleteAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        return back()->with('success', 'Profile photo removed.');
    }

    /**
     * Downscale an uploaded image to fit within 256×256 (only shrinks) using
     * GD, returning the encoded bytes. Falls back to the original bytes if
     * the image can't be decoded.
     */
    private function downscaleAvatar(UploadedFile $file, string $ext): string
    {
        $src = $file->getRealPath();
        $info = @getimagesize($src);
        if (! $info) {
            return (string) file_get_contents($src);
        }

        [$w, $h] = $info;
        $max = 256;
        $scale = min(1, $max / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));

        $mime = $info['mime'] ?? '';
        $image = match (true) {
            str_contains($mime, 'png') => @imagecreatefrompng($src),
            str_contains($mime, 'webp') => @imagecreatefromwebp($src),
            default => @imagecreatefromjpeg($src),
        };
        if (! $image) {
            return (string) file_get_contents($src);
        }

        $canvas = imagecreatetruecolor($nw, $nh);
        if (in_array($ext, ['png', 'webp'], true)) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
        }
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);

        ob_start();
        match ($ext) {
            'png' => imagepng($canvas),
            'webp' => imagewebp($canvas),
            default => imagejpeg($canvas, null, 85),
        };
        $bytes = (string) ob_get_clean();

        imagedestroy($image);
        imagedestroy($canvas);

        return $bytes;
    }
}
