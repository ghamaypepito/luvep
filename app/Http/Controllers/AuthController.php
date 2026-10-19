<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Inertia\Inertia;

class AuthController extends Controller
{
    /**
     * Show the login form.
     */
    public function showLoginForm()
    {
        return Inertia::render('auth/Login');
    }

    /**
     * Handle an authentication attempt.
     */
    /** Max failed login attempts before a one-minute lockout. */
    private const MAX_LOGIN_ATTEMPTS = 5;

    public function login(Request $request)
    {
        // Two throttles: email+IP (brute-force on one account) and IP-only
        // (one IP spraying many accounts). Either tripping locks out for a
        // minute; a successful login clears both.
        [$emailKey, $ipKey] = $this->loginThrottleKeys($request);

        foreach ([$emailKey, $ipKey] as $key) {
            if (RateLimiter::tooManyAttempts($key, self::MAX_LOGIN_ATTEMPTS)) {
                $seconds = RateLimiter::availableIn($key);
                abort(429, "Too many login attempts. Please try again in {$seconds} seconds.", [
                    'Retry-After' => (string) $seconds,
                ]);
            }
        }

        $credentials = $request->validate([
            // Tightened from a bare 'string': a new login must be a valid
            // email. We still accept a value that matches an existing
            // account's email verbatim, so the one legacy username-style
            // account ('adminepathways') keeps working — new garbage emails
            // (which match no user) are rejected. See build notes.
            'email' => [
                'required',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (! filter_var($value, FILTER_VALIDATE_EMAIL)
                        && ! \App\Models\User::where('email', $value)->exists()) {
                        $fail('Please enter a valid email address.');
                    }
                },
            ],
            'password' => ['required'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $remember = $request->boolean('remember');

        if (Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']], $remember)) {
            // Deactivated accounts have a valid password but no access.
            if (! Auth::user()->is_active) {
                Auth::logout();

                return back()->withErrors(['email' => 'This account has been deactivated. Please contact an administrator.'])->onlyInput('email');
            }
            // Clean slate on success — failed-attempt counters reset.
            RateLimiter::clear($emailKey);
            RateLimiter::clear($ipKey);

            $request->session()->regenerate();

            $user = Auth::user();

            // Stamp last_login_at so admins can see which lead accounts are
            // dormant. Quiet update — no model events fire.
            $user->forceFill(['last_login_at' => now()])->saveQuietly();

            ActivityLog::record('login', ['description' => $user->name.' signed in']);

            // Leads always land on their own dashboard — bypass intended()
            // which can carry over an admin URL from a prior session and
            // cause a misleading 403 immediately after login.
            if ($user->isLead()) {
                return redirect()->to($user->homeRoute())
                    ->with('success', 'Welcome back, '.$user->name.'!');
            }

            // Only return to this site or the explicitly configured HR host.
            $intended = $request->session()->get('url.intended');
            if (is_string($intended)) {
                $parts = parse_url($intended);
                $relative = str_starts_with($intended, '/') && ! str_starts_with($intended, '//') && ! str_contains($intended, '\\');
                $allowedOrigins = [$request->getSchemeAndHttpHost()];
                if (config('hr.enabled')) {
                    $allowedOrigins[] = rtrim(config('hr.url'), '/');
                }
                $origin = ($parts['scheme'] ?? '').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
                $allowed = $parts && ! isset($parts['user']) && ! isset($parts['pass']) && ! str_contains($intended, '\\')
                    && in_array($origin, $allowedOrigins, true);
                if (! $relative && ! $allowed) {
                    $request->session()->forget('url.intended');
                }
            }
            $destination = $request->session()->get('url.intended');
            if ($request->header('X-Inertia') && is_string($destination) && str_starts_with($destination, rtrim(config('hr.url'), '/').'/')
                && parse_url($destination, PHP_URL_HOST) !== $request->getHost()) {
                $request->session()->forget('url.intended');

                return Inertia::location($destination);
            }

            return redirect()->intended($user->homeRoute())
                ->with('success', 'Welcome back, '.$user->name.'!');
        }

        // Count this failed attempt against both throttles (decay 60s).
        RateLimiter::hit($emailKey, 60);
        RateLimiter::hit($ipKey, 60);

        ActivityLog::record('login.failed', [
            'actor_name' => $credentials['email'],
            'portal' => 'public',
            'description' => 'Failed sign-in attempt for '.$credentials['email'],
        ]);

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    /**
     * Rate-limiter keys for a login attempt: [email+IP, IP].
     *
     * @return array{0: string, 1: string}
     */
    private function loginThrottleKeys(Request $request): array
    {
        $email = Str::lower((string) $request->input('email'));
        $ip = $request->ip();

        return ["login:{$email}|{$ip}", "login-ip:{$ip}"];
    }

    /**
     * Shared password strength rule (min 8, mixed case, letters, numbers).
     */
    public static function passwordRules(): array
    {
        return ['required', 'confirmed', PasswordRule::defaults()];
    }

    // ─── Password reset ───────────────────────────────────────────────────

    public function showForgotPassword()
    {
        return Inertia::render('auth/ForgotPassword');
    }

    /**
     * Send the reset link. Always returns the same success message whether
     * or not the email exists — no account enumeration.
     */
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return back()->with('success', 'If an account exists for that email, a password reset link is on its way.');
    }

    public function showResetPassword(Request $request, string $token)
    {
        return Inertia::render('auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    /**
     * Complete the reset. Token validity + expiry (60 min, see
     * config/auth.php) are enforced by the password broker.
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => self::passwordRules(),
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect('/login')->with('success', 'Your password has been reset — please sign in.');
        }

        return back()->withErrors(['email' => __($status)]);
    }

    /**
     * Log the user out of the application.
     */
    public function logout(Request $request)
    {
        if ($user = $request->user()) {
            ActivityLog::record('logout', ['description' => $user->name.' signed out']);
        }

        Auth::logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        if (config('hr.enabled') && $request->getHost() === config('hr.host')) {
            return Inertia::location(rtrim(config('hr.main_url'), '/').'/login');
        }

        return redirect('/')->with('success', 'Logged out successfully');
    }
}
