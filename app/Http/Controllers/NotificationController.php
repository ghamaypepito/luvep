<?php

namespace App\Http\Controllers;

use App\Support\NotificationFormatter;
use Illuminate\Http\Request;

/**
 * Read path for the in-app notification inbox. Notifications are written
 * by the various App\Notifications\* classes through Laravel's database
 * channel; this controller surfaces them to the current user (bell
 * dropdown + full page) and handles read/dismiss actions.
 *
 * Routes are role-agnostic (auth only) — every authenticated user reads
 * their own notifications, scoped via the Notifiable relations so there
 * is no cross-user leak.
 */
class NotificationController extends Controller
{
    private const RECENT_LIMIT = 10;

    /** Full paginated inbox page. Filter: all | unread | read. */
    public function index(Request $request)
    {
        abort_if(config('hr.enabled') && app(\App\Services\Hr\HrContext::class)->isPreview($request), 403, 'Exit employee preview to open notifications.');
        $user = $request->user();
        $filter = in_array($request->query('filter'), ['unread', 'read'], true)
            ? $request->query('filter')
            : 'all';

        $source = match ($filter) {
            'unread' => $user->unreadNotifications(),
            'read' => $user->readNotifications(),
            default => $user->notifications(),
        };

        $notifications = $source
            ->paginate(20)
            ->withQueryString()
            ->through(fn ($n) => $this->serialize($n));

        return inertia($this->pageComponent($user), [
            'notifications' => $notifications,
            'filter' => $filter,
            'counts' => [
                'all' => $user->notifications()->count(),
                'unread' => $user->unreadNotifications()->count(),
                'read' => $user->readNotifications()->count(),
            ],
        ]);
    }

    /**
     * Resolve the page component by role so the shared notifications page
     * is wrapped in the user's own portal layout (app.jsx auto-wraps by
     * the component-name prefix). Each portal/<role>/Notifications page is
     * a thin wrapper re-exporting notifications/Index.
     */
    private function pageComponent($user): string
    {
        if (request()->getHost() === config('hr.host')) {
            return 'hr/Notifications';
        }

        return match ($user->role) {
            'sales' => 'portal/sales/Notifications',
            'education' => 'portal/education/Notifications',
            'english' => 'portal/english/Notifications',
            'accommodation' => 'portal/accommodation/Notifications',
            'lead' => 'portal/lead/Notifications',
            'immigration_adviser' => 'portal/immigration-adviser/Notifications',
            'immigration', 'immigration_manager' => 'portal/immigration/Notifications',
            default => 'admin/Notifications', // admin + super_admin
        };
    }

    /** Recent 10 (read + unread) for the bell dropdown. */
    public function recent(Request $request)
    {
        abort_if(config('hr.enabled') && app(\App\Services\Hr\HrContext::class)->isPreview($request), 403, 'Exit employee preview to open notifications.');
        $user = $request->user();

        return response()->json([
            'notifications' => $user->notifications()
                ->latest()
                ->limit(self::RECENT_LIMIT)
                ->get()
                ->map(fn ($n) => $this->serialize($n))
                ->values(),
            'unread_count' => $user->unreadNotifications()->count(),
        ]);
    }

    /** Lightweight count endpoint (for future polling). */
    public function unreadCount(Request $request)
    {
        abort_if(config('hr.enabled') && app(\App\Services\Hr\HrContext::class)->isPreview($request), 403);

        return response()->json([
            'count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markAsRead(Request $request, string $id)
    {
        // Scoped to the user's own notifications — findOrFail 404s on
        // someone else's id, so there's no cross-user mutation.
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return $this->respond($request, ['ok' => true]);
    }

    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->respond($request, ['ok' => true]);
    }

    public function destroy(Request $request, string $id)
    {
        $request->user()->notifications()->findOrFail($id)->delete();

        return $this->respond($request, ['ok' => true]);
    }

    /** Return JSON for XHR callers, otherwise redirect back for Inertia. */
    private function respond(Request $request, array $payload)
    {
        if ($request->wantsJson()) {
            return response()->json($payload);
        }

        return back();
    }

    /**
     * Shape one notification row for the frontend — raw stored data + read
     * state, enriched with the NotificationFormatter UI mapping (icon /
     * color / title / body / url).
     */
    private function serialize($n): array
    {
        $formatted = NotificationFormatter::format([
            'type' => $n->type,
            'data' => $n->data,
        ]);

        return [
            'id' => $n->id,
            'type' => $formatted['type'],
            'data' => $n->data,
            'icon' => $formatted['icon'],
            'color' => $formatted['color'],
            'title' => $formatted['title'],
            'body' => $formatted['body'],
            'url' => $formatted['url'],
            'is_read' => $n->read_at !== null,
            'read_at' => optional($n->read_at)?->toIso8601String(),
            'created_at' => optional($n->created_at)?->toIso8601String(),
        ];
    }
}
