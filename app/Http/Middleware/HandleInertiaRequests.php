<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        if (config('hr.enabled') && str_starts_with($request->route()?->getName() ?? '', 'hr.')) {
            $context = app(\App\Services\Hr\HrContext::class);
            $hr = $context->props($request);
            $user = $hr['preview'] ? $context->subject($request)->user : $request->user();

            return [...parent::share($request), 'auth' => ['user' => $user?->only(['id', 'name', 'email', 'avatar_url']), 'modules' => [], 'portals' => [], 'can_manage_modules' => false],
                'hr' => $hr, 'flash' => ['success' => $request->session()->get('success'), 'error' => $request->session()->get('error')]];
        }

        return [
            ...parent::share($request),
            'hr' => fn () => config('hr.enabled') && $request->user() ? app(\App\Services\Hr\HrContext::class)->props($request) : null,
            'auth' => [
                'user' => $request->user(),
                // Drives the topbar AI button + lead health badge. True only
                // when the config kill switch AND the tenant toggle are on.
                'ai_enabled' => (bool) config('ai.enabled') && (bool) \App\Models\Setting::get('ai_enabled', true),
                // Restricted modules (config/modules.php) this user may see, so
                // the sidebar can hide gated items. Grandfathered modules aren't
                // listed here and stay visible by role. Super admins get all.
                'modules' => $request->user()?->grantedModules() ?? [],
                // Only super admins reach Module Management.
                'can_manage_modules' => $request->user()?->isSuperAdmin() ?? false,
                // Department portals this user can switch between (their role +
                // any granted in Module Management). Only populated when there is
                // more than one, so the topbar switcher shows only when useful.
                'portals' => $this->switchablePortals($request->user()),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'lead_id' => $request->session()->get('lead_id'),
                'intake_id' => $request->session()->get('intake_id'),
                'review_success' => $request->session()->get('review_success'),
                'review_id' => $request->session()->get('review_id'),
                'edit_link_url' => $request->session()->get('edit_link_url'),
                'edit_link_intake_id' => $request->session()->get('edit_link_intake_id'),
                // Admin Portal Invitations — single-shot credential surfacing.
                'invitation_link' => $request->session()->get('invitation_link'),
                'invitation_link_lead_id' => $request->session()->get('invitation_link_lead_id'),
                'generated_credentials' => $request->session()->get('generated_credentials'),
                // Lead CSV import summary — surfaces +N new / N updated / N skipped chip.
                'import_summary' => $request->session()->get('import_summary'),
                // Assessment draft save — distinct from `success` so the page
                // doesn't flip to the "submission complete" screen on a save.
                'draft_saved' => $request->session()->get('draft_saved'),
                'draft_id' => $request->session()->get('draft_id'),
                // Visa intake post-submit — used by the four visa intake
                // pages to swap the form for a persistent thank-you modal
                // once the controller saves the row. Carries the visa name
                // so the modal copy can read "Thanks for submitting your
                // Work Visa (AEWV) details" etc.
                'intake_submitted' => $request->session()->get('intake_submitted'),
            ],
            // Public contact channels for the sticky CTA bar, floating contact
            // widget, and footer. Components hide channels with empty values.
            'contact' => [
                'phone' => config('services.contact.phone'),
                'whatsapp' => config('services.contact.whatsapp'),
                'messenger' => config('services.contact.messenger'),
                'facebook' => config('services.contact.facebook'),
                'email' => config('services.contact.email'),
            ],
            // Portal-sidebar badge counts — surfaces "what needs my attention
            // today" on the nav itself. Lazy so the queries only run for the
            // portal that's actually loading.
            'sidebarBadges' => fn () => $this->sidebarBadges($request),
            // Global unread-notification count — drives the topbar bell badge
            // across every portal. Re-shared on every Inertia navigation so
            // the badge stays fresh without polling.
            'notifications' => [
                'unread_count' => $request->user()?->unreadNotifications()->count() ?? 0,
            ],
            // Staff-preview state for the client portal — non-null only when an
            // admin is browsing /portal/lead without a lead of their own.
            'leadPortalPreview' => fn () => $this->leadPortalPreview($request),
        ];
    }

    /**
     * Department portals a signed-in staff user can switch between — their own
     * role's portal plus any granted in Module Management. Returns an empty list
     * for admins (who use the /admin area) and whenever there is only one, so the
     * topbar switcher renders only when it actually helps.
     *
     * @return list<array{key:string,label:string,href:string}>
     */
    private function switchablePortals(?\App\Models\User $user): array
    {
        if (! $user || $user->isAdmin() || $user->isLead()) {
            return [];
        }

        $labels = [
            'sales' => 'Sales', 'education' => 'Education', 'english' => 'English',
            'immigration' => 'Immigration', 'accommodation' => 'Accommodation',
            'finance' => 'Finance', 'agent' => 'Agent', 'sub_agent' => 'Sub-Agent',
        ];

        $keys = $user->accessiblePortals();
        if (count($keys) < 2) {
            return [];
        }

        return collect($keys)->map(fn (string $k) => [
            'key' => $k,
            'label' => $labels[$k] ?? ucfirst($k),
            'href' => "/portal/{$k}/dashboard",
        ])->values()->all();
    }

    /** Client-portal preview banner data (admins viewing a sample client). */
    private function leadPortalPreview(Request $request): ?array
    {
        $user = $request->user();
        if (! $user || ! str_starts_with($request->path(), 'portal/lead')) {
            return null;
        }
        if ($user->lead || ! $user->isAtLeast('admin')) {
            return null;
        }

        $currentId = session('lead_portal_preview_id');
        $current = $currentId ? \App\Models\Lead::find($currentId) : null;
        $name = fn ($l) => trim("{$l->first_name} {$l->last_name}") ?: $l->lead_id;

        $candidates = \App\Models\Lead::query()
            ->whereHas('portalUser')
            ->latest()
            ->limit(30)
            ->get(['id', 'lead_id', 'first_name', 'last_name'])
            ->map(fn ($l) => ['id' => $l->id, 'name' => $name($l)])
            ->values();

        return [
            'active' => true,
            'name' => $current ? $name($current) : 'sample client',
            'current_id' => $current?->id,
            'candidates' => $candidates,
        ];
    }

    /**
     * Per-portal sidebar badge counts. Returns only the badges relevant to
     * the current URL prefix so we never run sales queries on the lead
     * portal etc.
     */
    private function sidebarBadges(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }

        $path = $request->path();
        $out = [];

        if (str_starts_with($path, 'portal/immigration-adviser')) {
            // Adviser portal — scoped to the adviser's own cases + sign-off.
            $caseScope = \App\Models\Lead::immigrationCase()
                ->when(! $user->isAdmin(), fn ($q) => $q->where('current_owner_id', $user->id));
            $myIds = (clone $caseScope)->pluck('id');
            $out['immigration-adviser'] = [
                'my_cases' => $myIds->count(),
                'pending_signoff' => $myIds->filter(fn ($id) => \App\Models\CaseAttestation::currentVerdict($id) === null || ! \App\Models\CaseAttestation::hasLodgementSignoff($id))->count(),
                'pending_verification' => \App\Models\LeadDocument::where('status', \App\Models\LeadDocument::STATUS_CHECKED)
                    ->whereHas('lead', fn ($q) => $q->immigrationCase())->count(),
                'notifications_unread' => $user->unreadNotifications()->count(),
            ];
        } elseif (str_starts_with($path, 'portal/immigration')) {
            $todayStart = now()->startOfDay();
            $weekStart = now()->startOfWeek();
            $out['immigration'] = [
                'new_assessments' => \App\Models\ResidentIntake::where('created_at', '>=', $weekStart)->count(),
                'new_leads_today' => \App\Models\Lead::where('created_at', '>=', $todayStart)->count(),
                'active_cases' => \App\Models\Lead::where('status', 'Visa Process')->count(),
                'docs_pending_review' => \App\Models\LeadDocument::whereIn('status', ['Submitted', 'UnderReview'])->count(),
                'notifications_unread' => $user->unreadNotifications()->count(),
            ];
        }

        if (str_starts_with($path, 'portal/education')) {
            $todayStart = now()->startOfDay();
            $weekStart = now()->startOfWeek();
            $out['education'] = [
                'new_leads_today' => \App\Models\Lead::where('created_at', '>=', $todayStart)->count(),
                'docs_pending_review' => \App\Models\LeadDocument::whereIn('status', ['Submitted', 'UnderReview'])->count(),
                // Assessments awaiting attention — every free-assessment /
                // education-enrolment entry (submitted or still a draft).
                'assessments' => \App\Models\Lead::whereIn('source', ['free-assessment', 'education-enrolment'])->count(),
                // Newly-booked consultations this week.
                'bookings_new' => \App\Models\Booking::where('created_at', '>=', $weekStart)->count(),
                // User reviews still awaiting moderation (status "New").
                'user_reviews_new' => \App\Models\UserReview::where('status', 'New')->count(),
                'notifications_unread' => $user->unreadNotifications()->count(),
            ];
        }

        if (str_starts_with($path, 'portal/sub-agent')) {
            // Scoped to the parent agent's referrals — the same row filter
            // SubAgentController applies, so the badge can never count a lead
            // the sub-agent is not allowed to open.
            $parentId = $user->parent_agent_id;
            $leadIds = $parentId ? \App\Models\Lead::where('agent_id', $parentId)->pluck('id') : collect();

            $out['sub-agent'] = [
                // Leads sitting in the "needs follow-up" end of the pipeline.
                'needs_follow_up' => $leadIds->isEmpty() ? 0 : \App\Models\Lead::whereIn('id', $leadIds)
                    ->whereIn('status', ['New Leads', 'Contact Attempted', 'Contacted for Booking', 'Missed the Meeting'])
                    ->count(),
                // Follow-up tasks that are due or past due and not snoozed.
                'follow_ups_due' => \App\Models\LeadTask::where('completed', false)
                    ->where(fn ($q) => $q->whereIn('lead_id', $leadIds)
                        ->orWhere(fn ($q2) => $q2->whereNull('lead_id')->where('created_by', $user->id)))
                    ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
                    ->whereNotNull('due_at')->where('due_at', '<=', now()->endOfDay())
                    ->count(),
            ];
        }

        if (str_starts_with($path, 'portal/agent')) {
            $todayStart = now()->startOfDay();
            $out['agent'] = [
                // Scoped to the agent's own leads only. Agents have no
                // notifications surface, so no unread badge here.
                'new_leads_today' => \App\Models\Lead::where('agent_id', $user->id)->where('created_at', '>=', $todayStart)->count(),
            ];
        }

        if (str_starts_with($path, 'portal/sales') || str_starts_with($path, 'admin')) {
            $todayStart = now()->startOfDay();
            $weekEnd = now()->endOfWeek();

            $out['sales'] = [
                'new_leads_today' => \App\Models\Lead::where('created_at', '>=', $todayStart)->count(),
                'tasks_open' => \App\Models\LeadTask::where('assignee_id', $user->id)->where('completed', false)->count(),
                'tasks_overdue' => \App\Models\LeadTask::where('assignee_id', $user->id)->where('completed', false)->whereNotNull('due_at')->where('due_at', '<', now())->count(),
                'bookings_this_week' => \App\Models\Booking::whereBetween('appointment_date', [now()->startOfWeek(), $weekEnd])->count(),
                'notifications_unread' => $user->unreadNotifications()->count(),
            ];
        }

        return $out;
    }
}
