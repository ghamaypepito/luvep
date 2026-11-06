<?php

namespace App\EpAgent;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PrivateResponse
{
    public static function error(int $status): Response
    {
        $status = in_array($status, [401, 403, 404, 409, 422, 429], true) ? $status : 503;

        return response()->json(['error' => match ($status) {
            401, 403 => 'ep_access_denied', 404 => 'ep_not_found', 409 => 'ep_conflict',
            422 => 'ep_invalid_request', 429 => 'ep_rate_limited', default => 'ep_unavailable',
        }], $status, ['Cache-Control' => 'no-store, private']);
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            app(Access::class)->assertStaff($request->user());
            $response = $next($request);
        } catch (\Throwable $exception) {
            // Do not expose private request content, SQL, traces or provider errors.
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503;
            $response = self::error($status);
        }
        if ($response->getStatusCode() >= 400) {
            $response = self::error($response->getStatusCode());
        }
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
