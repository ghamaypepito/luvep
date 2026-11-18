<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\UserView;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ApplyUserView
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard();
        $actor = $guard->user();
        // Re-read authority: neither a stored target ID nor a cached role grants switching.
        if ($actor) {
            $actor = $actor->fresh();
            if ($actor) {
                $guard->setUser($actor);
            } else {
                $guard->forgetUser();
            }
        }
        $state = $request->session()->get('user_view');
        $target = null;
        if ($state) {
            $target = ($actor?->isSuperAdmin() && $actor->is_active) ? User::where('is_active', true)->find($state['target_id'] ?? null) : null;
            if (! $target) {
                $request->session()->forget('user_view');
                if ($actor) {
                    UserView::audit($request, $actor, null, 'user_view.revoked');
                }
                $state = null;
            }
        }
        // Exit/logout always use the real identity and remain reachable from denied pages.
        if ($request->routeIs('logout', 'hr.logout') && $target) {
            UserView::audit($request, $actor, $target, 'user_view.ended', ['reason' => 'logout']);
        }
        if ($request->routeIs('*user-view.exit', 'logout', 'hr.logout')) {
            return $next($request);
        }
        $expected = $state['token'] ?? 'none';
        $supplied = $request->header('X-Luvep-User-View', $request->input('_user_view_token', 'none'));
        if (! $request->isMethodSafe() && (! is_string($supplied) || ! hash_equals((string) $expected, $supplied))) {
            return response()->json(['message' => 'Your user view changed in another tab. Refresh this page before making changes.'], 409);
        }
        if ($request->routeIs('*user-view.*') || ! $target) {
            return $next($request);
        }

        $request->attributes->set('user_view_actor', $actor);
        $request->attributes->set('user_view_target', $target);
        $guard->setUser($target);
        $log = null;
        $status = 500;
        try {
            if (! $request->isMethodSafe()) {
                // Persist before dispatch; if the audit store fails, the action never runs.
                $log = UserView::audit($request, $actor, $target, 'user_view.action', [
                    'method' => $request->method(), 'route' => $request->route()?->getName(),
                    'path' => $request->route()?->uri(),
                    'record_ids' => collect($request->route()?->parameters() ?? [])->only(['lead', 'id', 'document', 'doc', 'case', 'user'])->filter(fn ($value) => is_scalar($value) && ctype_digit((string) $value))->all(),
                    'status' => null,
                ]);
                abort_if($request->routeIs('hr.preview.*', 'hr.main.preview.*', 'hr.portal.preview.*', 'password.*') || $request->is('login', 'register') || $request->hasAny(['password', 'current_password', 'password_confirmation']), 403, 'Return to Super Admin before changing authentication or starting another preview.');
            }
            $response = $next($request);
            $status = $response->getStatusCode();

            return $response;
        } catch (\Throwable $e) {
            $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $e->getStatusCode() : 500;
            throw $e;
        } finally {
            // Restore before AuthenticateSession writes its password hash, and never call login().
            $guard->setUser($actor);
            if ($log) {
                $log->update(['metadata' => array_merge($log->metadata, ['status' => $status])]);
            }
        }
    }
}
