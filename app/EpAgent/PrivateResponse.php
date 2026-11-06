<?php

namespace App\EpAgent;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PrivateResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            app(Access::class)->assertStaff($request->user());
            $response = $next($request);
        } catch (\Throwable $exception) {
            // Do not expose private request content, SQL, traces or provider errors.
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 503;
            $response = response()->json(['error' => match ($status) {
                403 => 'ep_access_denied', 404 => 'ep_not_found', 409 => 'ep_conflict',
                422 => 'ep_invalid_request', default => 'ep_unavailable',
            }], $status);
        }
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
