<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /** Highest tier — can hard-delete + edit anything across departments. */
    public const ROLE_SUPER_ADMIN = 'super_admin';

    /** Role with access to every department portal and the /admin area. */
    public const ROLE_ADMIN = 'admin';

    /** Immigration department-head — can edit visa types incl. price. */
    public const ROLE_IMMIGRATION_MANAGER = 'immigration_manager';

    /**
     * Immigration adviser — the Licensed Immigration Adviser (LIA). Full write
     * on cases; the licence (see holdsCurrentLicence()) — not the role — gates
     * advice-bearing artifacts via AdviceBearingPolicy. Read-only on the visa
     * catalogue (a manager/admin function). Superseded the old blanket
     * read-only rule in Build 12 §2.
     */
    public const ROLE_IMMIGRATION_ADVISER = 'immigration_adviser';

    /** Role for an external Lead who logs in to the Leads Portal. */
    public const ROLE_LEAD = 'lead';

    /** A previously-active Lead account whose portal access was revoked. The
     *  User row is kept (history/linked records) but can no longer log in. */
    public const ROLE_REVOKED_LEAD = 'revoked_lead';

    /** A sub-agent works the referral leads of ONE recruiting agent (their
     *  `parent_agent_id`), with a sales-style pipeline scoped to that agent. */
    public const ROLE_SUB_AGENT = 'sub_agent';

    /** Department portals a non-admin user can be assigned to. */
    public const PORTAL_ROLES = ['sales', 'education', 'english', 'immigration', 'accommodation', 'finance', 'agent', 'sub_agent'];

    /** Roles that resolve to the Immigration portal. */
    public const IMMIGRATION_ROLES = [
        self::ROLE_IMMIGRATION_MANAGER,
        self::ROLE_IMMIGRATION_ADVISER,
    ];

    /** Tier names mapped to a numeric weight — bigger = more power. */
    private const ROLE_RANK = [
        self::ROLE_SUPER_ADMIN => 100,
        self::ROLE_ADMIN => 80,
        self::ROLE_IMMIGRATION_MANAGER => 50,
        self::ROLE_IMMIGRATION_ADVISER => 20,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'module_permissions',
        'portal_access',
        'referral_code',
        'parent_agent_id',
        'lead_id',
        'iaa_licence_number',
        'iaa_licence_type',
        'iaa_licence_expiry',
        'iaa_licence_verified_at',
        'avatar_path',
        'signature_path',
        'signature_updated_at',
        // Contact details — primarily captured for recruiting Agents
        // (location + phone shown in the admin user form), harmless for
        // other roles.
        'phone',
        'location',
    ];

    /** Computed attributes always included in array/JSON output. */
    protected $appends = ['avatar_url'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // Exposed as the resolved `auth.modules` list instead (grantedModules()).
        'module_permissions',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'iaa_licence_expiry' => 'date',
            'iaa_licence_verified_at' => 'date',
            'signature_updated_at' => 'datetime',
            'module_permissions' => 'array',
            'hr_access_version' => 'integer',
            'portal_access' => 'array',
            'password' => 'hashed',
        ];
    }

    /**
     * Restricted modules (config/modules.php) this user may see. Super admins
     * always see every restricted module; everyone else sees only those
     * explicitly granted to them. Grandfathered modules aren't gated here.
     *
     * @return list<string>
     */
    public function grantedModules(): array
    {
        $restricted = config('modules.restricted', []);

        // Every valid key: each module + its dotted feature keys.
        $valid = [];
        foreach ($restricted as $key => $def) {
            $valid[] = $key;
            foreach (array_keys($def['features'] ?? []) as $f) {
                $valid[] = "{$key}.{$f}";
            }
        }

        if ($this->isSuperAdmin()) {
            return array_values($valid);
        }

        $perms = array_values(array_intersect((array) ($this->module_permissions ?? []), $valid));
        $out = $perms;

        foreach ($restricted as $key => $def) {
            $children = array_keys($def['features'] ?? []);
            $adminDefault = ($def['admin_default'] ?? false) && $this->role === self::ROLE_ADMIN;

            // Admin-tier module, or a whole-module grant → expose parent + every feature.
            if ($adminDefault || in_array($key, $perms, true)) {
                $out[] = $key;
                foreach ($children as $f) {
                    $out[] = "{$key}.{$f}";
                }
            }
            // Any feature grant → also expose the parent (for parent-level checks).
            if (collect($perms)->contains(fn ($p) => str_starts_with((string) $p, "{$key}."))) {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * May this user see a restricted module or feature key (e.g. "dtr" or
     * "dtr.reports")? Super admins see everything; admins see admin_default
     * modules; a whole-module grant implies its features; any feature grant
     * makes the parent visible.
     */
    public function canSeeModule(string $key): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $parent = str_contains($key, '.') ? explode('.', $key, 2)[0] : $key;
        $def = config("modules.restricted.{$parent}");

        // Admin-tier modules (historically admin surfaces) — admins always see them.
        if (($def['admin_default'] ?? false) && $this->role === self::ROLE_ADMIN) {
            return true;
        }

        $perms = (array) ($this->module_permissions ?? []);
        if (in_array($key, $perms, true)) {
            return true;
        }

        // A dotted feature is covered by a whole-module grant on its parent.
        if (str_contains($key, '.')) {
            return in_array($parent, $perms, true);
        }

        // A parent key is visible when any of its features is granted.
        return collect($perms)->contains(fn ($p) => str_starts_with((string) $p, "{$key}."));
    }

    /**
     * Whether this user has a usable signature — the column AND the file it
     * points at. A path alone isn't enough: if the file has since been
     * removed the signature block renders blank, so anything that warns
     * about a missing signature has to check the file too.
     */
    public function hasSignature(): bool
    {
        return $this->signature_path
            && \Illuminate\Support\Facades\Storage::disk('local')->exists($this->signature_path);
    }

    /**
     * The staff signature as a base64 data URI, or null when none is set.
     * Signatures live on the private disk (never a public URL) and are
     * embedded straight into generated PDFs / previews.
     */
    public function signatureDataUri(): ?string
    {
        if (! $this->signature_path) {
            return null;
        }

        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $disk->exists($this->signature_path)) {
            return null;
        }

        $ext = strtolower(pathinfo($this->signature_path, PATHINFO_EXTENSION));
        $mime = $ext === 'jpg' || $ext === 'jpeg' ? 'image/jpeg' : 'image/png';

        return "data:{$mime};base64,".base64_encode($disk->get($this->signature_path));
    }

    /**
     * Same as signatureDataUri() but with transparent (PNG) / near-white
     * (JPEG) borders stripped so the visible ink centers cleanly when
     * dropped into a signature block. Signature-pad exports tend to have
     * a huge canvas with a tiny scribble in one corner — without trimming
     * the ink looks off-center no matter how you align the <img>.
     */
    public function signatureDataUriTrimmed(): ?string
    {
        if (! $this->signature_path) {
            return null;
        }
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $disk->exists($this->signature_path)) {
            return null;
        }
        if (! function_exists('imagecreatefromstring')) {
            // GD absent — fall back to the raw image rather than error.
            return $this->signatureDataUri();
        }

        $binary = $disk->get($this->signature_path);
        $img = @imagecreatefromstring($binary);
        if (! $img) {
            return $this->signatureDataUri();
        }

        $w = imagesx($img);
        $h = imagesy($img);
        $left = $w;
        $right = 0;
        $top = $h;
        $bottom = 0;

        // Alpha threshold — pixels with alpha above this are treated as
        // background. 100 (out of 127) tolerates faint anti-aliased edges
        // without eating into thin pen strokes.
        $alphaThreshold = 100;
        // Brightness threshold for the JPEG fallback path — anything
        // above 240 average RGB counts as background.
        $brightThreshold = 240;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgba = imagecolorat($img, $x, $y);
                $a = ($rgba >> 24) & 0x7F;
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                $isInk = $a < $alphaThreshold && ($r + $g + $b) / 3 < $brightThreshold;
                if (! $isInk) {
                    continue;
                }
                if ($x < $left) {
                    $left = $x;
                }
                if ($x > $right) {
                    $right = $x;
                }
                if ($y < $top) {
                    $top = $y;
                }
                if ($y > $bottom) {
                    $bottom = $y;
                }
            }
        }

        if ($right < $left || $bottom < $top) {
            imagedestroy($img);

            return $this->signatureDataUri();
        }

        // Small breathing room around the ink so descenders don't
        // touch the crop edge.
        $pad = 4;
        $left = max(0, $left - $pad);
        $top = max(0, $top - $pad);
        $right = min($w - 1, $right + $pad);
        $bottom = min($h - 1, $bottom + $pad);

        $cw = $right - $left + 1;
        $ch = $bottom - $top + 1;
        $out = imagecreatetruecolor($cw, $ch);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        $transparent = imagecolorallocatealpha($out, 0, 0, 0, 127);
        imagefill($out, 0, 0, $transparent);
        imagecopy($out, $img, 0, 0, $left, $top, $cw, $ch);

        ob_start();
        imagepng($out);
        $trimmed = ob_get_clean();
        imagedestroy($img);
        imagedestroy($out);

        return 'data:image/png;base64,'.base64_encode($trimmed);
    }

    /**
     * Public URL of the user's profile photo, or null when none is set
     * (the UI falls back to an initials avatar from the name).
     */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path
            ? \Illuminate\Support\Facades\Storage::disk('public')->url($this->avatar_path)
            : null;
    }

    /**
     * Whether this user is an admin (full access to /admin and every portal).
     * Super-admins implicitly satisfy any admin check.
     */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN
            || $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function isImmigrationManager(): bool
    {
        return $this->role === self::ROLE_IMMIGRATION_MANAGER;
    }

    public function isImmigrationAdviser(): bool
    {
        return $this->role === self::ROLE_IMMIGRATION_ADVISER;
    }

    /**
     * Whether this user may author or approve advice-bearing artifacts — the
     * content-level control that replaces the old "immigration_adviser is
     * read-only" role rule (Build 12 §2).
     *
     * The gate is the LICENCE, not the role: a current IAA licence means a
     * non-empty number AND an expiry in the future. A lapsed licence closes
     * the gate automatically, and no role string can substitute for it — so a
     * system/AI actor (which never holds a licence) can never approve advice.
     *
     * Reuses the existing iaa_licence_* columns (the same licence printed on
     * the engagement pack and shown on the Profile page), so there is one
     * source of truth for "is this person licensed".
     */
    public function holdsCurrentLicence(): bool
    {
        return filled($this->iaa_licence_number)
            && $this->iaa_licence_expiry !== null
            && $this->iaa_licence_expiry->isFuture();
    }

    /**
     * Whether this user is an external lead logging into the Leads Portal.
     */
    public function isLead(): bool
    {
        return $this->role === self::ROLE_LEAD;
    }

    /**
     * Exact-role check.
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Any-of-the-listed-roles check — convenience for policies.
     */
    public function hasAnyRole(array $roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Numeric tier comparison — useful in helpers like `isAtLeast('admin')`.
     */
    public function isAtLeast(string $tier): bool
    {
        $mine = self::ROLE_RANK[$this->role] ?? 0;
        $needed = self::ROLE_RANK[$tier] ?? PHP_INT_MAX;

        return $mine >= $needed;
    }

    /**
     * Whether this user may open the given department portal.
     * Admins (and super-admins) may open any portal; immigration-specific
     * roles resolve to the immigration portal; everyone else only their own.
     */
    public function canAccessPortal(string $portal): bool
    {
        // Super-admin-only surfaces must NOT let plain admins through —
        // resolve this gate by exact-role check before the broader
        // admin-pass below.
        if ($portal === self::ROLE_SUPER_ADMIN) {
            return $this->isSuperAdmin();
        }
        if ($this->isAdmin()) {
            return true;
        }
        if ($portal === 'immigration' && in_array($this->role, self::IMMIGRATION_ROLES, true)) {
            return true;
        }
        // The adviser's own portal (separate from the manager's immigration
        // portal). Only the adviser role — admins already passed above.
        if ($portal === 'immigration-adviser') {
            return $this->role === self::ROLE_IMMIGRATION_ADVISER;
        }
        // Extra portals granted in Module Management (beyond the user's role).
        if (in_array($portal, (array) ($this->portal_access ?? []), true)) {
            return true;
        }

        return $this->role === $portal;
    }

    /**
     * Every department portal this staff user can reach — their own role's
     * portal plus any granted in Module Management. Admins/super-admins reach
     * them all. Returns portal keys (a subset of PORTAL_ROLES). Drives the
     * topbar portal switcher.
     *
     * @return list<string>
     */
    public function accessiblePortals(): array
    {
        if ($this->isAdmin()) {
            return self::PORTAL_ROLES;
        }

        $portals = [];
        // The user's own role, when it is itself a department portal.
        if (in_array($this->role, self::PORTAL_ROLES, true)) {
            $portals[] = $this->role;
        }
        // Immigration sub-roles land on the immigration portal.
        if (in_array($this->role, self::IMMIGRATION_ROLES, true)) {
            $portals[] = 'immigration';
        }
        foreach ((array) ($this->portal_access ?? []) as $p) {
            if (in_array($p, self::PORTAL_ROLES, true)) {
                $portals[] = $p;
            }
        }

        return array_values(array_unique($portals));
    }

    /**
     * Which department's message templates this user manages, or null for
     * admins/super-admins (who manage every department's templates plus the
     * shared/global set). Immigration sub-roles resolve to 'immigration'.
     */
    public function templateDepartment(): ?string
    {
        if ($this->isAdmin()) {
            return null;
        }
        if (in_array($this->role, self::IMMIGRATION_ROLES, true)) {
            return 'immigration';
        }

        return in_array($this->role, self::PORTAL_ROLES, true) ? $this->role : null;
    }

    /**
     * Path the user should land on after logging in.
     */
    public function homeRoute(): string
    {
        if ($this->isLead()) {
            return '/portal/lead/dashboard';
        }
        if ($this->isSuperAdmin()) {
            return '/admin/super-dashboard';
        }
        // The adviser lands on their own portal; the manager keeps the full one.
        if ($this->role === self::ROLE_IMMIGRATION_ADVISER) {
            return '/portal/immigration-adviser/dashboard';
        }
        if (in_array($this->role, self::IMMIGRATION_ROLES, true)) {
            return '/portal/immigration/dashboard';
        }

        // Sub-agent portal uses a hyphenated URL prefix; its role is underscored.
        if ($this->role === self::ROLE_SUB_AGENT) {
            return '/portal/sub-agent/dashboard';
        }

        return in_array($this->role, self::PORTAL_ROLES, true)
            ? "/portal/{$this->role}/dashboard"
            : '/admin/dashboard';
    }

    /** The Lead record this account owns — only set for role='lead' users. */
    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    /** Leads this user recruited (role='agent'); drives the Agent portal. */
    public function agentLeads()
    {
        return $this->hasMany(Lead::class, 'agent_id');
    }

    /** The recruiting agent a sub-agent works under (role='sub_agent' only). */
    public function parentAgent()
    {
        return $this->belongsTo(User::class, 'parent_agent_id');
    }

    /**
     * Ensure this agent has a public referral code + return it. Idempotent —
     * generates a unique "AGT-XXXXXX" on first call, then returns the stored
     * value on every call after. No-op for non-agent roles (returns null).
     *
     * Used by the Agent portal to build the /register?ref=CODE link the
     * agent shares with prospective leads. When a lead submits through that
     * link the storeRegistration handler resolves the code back to this
     * user id and stamps it on Lead.agent_id.
     */
    public function ensureReferralCode(): ?string
    {
        if ($this->role !== 'agent') {
            return null;
        }
        if ($this->referral_code) {
            return $this->referral_code;
        }

        for ($i = 0; $i < 5; $i++) {
            $candidate = 'AGT-'.strtoupper(\Illuminate\Support\Str::random(6));
            if (! self::where('referral_code', $candidate)->exists()) {
                $this->forceFill(['referral_code' => $candidate])->save();

                return $candidate;
            }
        }

        return null;
    }
}
