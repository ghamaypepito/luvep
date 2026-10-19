<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;

class StartLuvepSharedSession extends StartSession
{
    public function handle($request, Closure $next)
    {
        if (! config('hr.enabled') || ! config('hr.shared_session') || ! in_array($request->getHost(), config('hr.session_hosts'), true)) {
            return parent::handle($request, $next);
        }

        $original = config('session');
        $store = $this->manager->driver();
        $originalName = $store->getName();
        $sharedName = 'luvep_shared_session';
        $cookieJar = app('cookie');
        config(['session.cookie' => $sharedName, 'session.domain' => config('hr.session_domain'), 'session.secure' => true, 'session.same_site' => 'lax']);
        $cookieJar->setDefaultPathAndDomain('/', config('hr.session_domain'), true, 'lax');
        $store->setName($sharedName);

        // EncryptCookies has already authenticated these values against the application key.
        // A distinct cookie name avoids ambiguous ordering with an old host-only cookie.
        if (! $request->cookies->has($sharedName) && $request->cookies->has($originalName)) {
            $request->cookies->set($sharedName, $request->cookies->get($originalName));
        }

        try {
            $response = parent::handle($request, function ($request) use ($next) {
                return app(AuthenticateSession::class)->handle($request, $next);
            });
            if ($request->getHost() === parse_url(config('hr.main_url'), PHP_URL_HOST)) {
                $response->headers->clearCookie($originalName, $original['path'], null, true, true, 'lax');
                $guard = Auth::guard();
                $name = $guard->getRecallerName();
                $recaller = $request->cookies->get($name);
                if ($recaller) {
                    $parts = explode('|', $recaller);
                    $user = $guard->user();
                    if ($user && count($parts) === 3 && (string) $user->id === $parts[0]
                        && hash_equals((string) $user->getRememberToken(), $parts[1])
                        && hash_equals($user->getAuthPassword(), $parts[2])) {
                        $cookieJar->queue($cookieJar->forever($name, $recaller));
                    }
                    $response->headers->clearCookie($name, '/', null, true, true, 'lax');
                }
            }

            return $response;
        } finally {
            config(['session' => $original]);
            $store->setName($originalName);
            $cookieJar->setDefaultPathAndDomain($original['path'], $original['domain'], $original['secure'], $original['same_site']);
        }
    }
}
