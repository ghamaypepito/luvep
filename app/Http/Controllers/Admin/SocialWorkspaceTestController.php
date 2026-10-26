<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Ghl\GhlConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class SocialWorkspaceTestController extends Controller
{
    public function index(Request $request, GhlConnection $ghl)
    {
        if ($request->header('X-Inertia')) {
            return Inertia::location(url('/social/compose'));
        }
        $path = Storage::disk('local')->path('social-workspace-test/v1/index.html');
        abort_unless(is_file($path), 503, 'The Social test release is unavailable.');
        $html = file_get_contents($path);
        $html = str_replace('<head>', '<head><meta name="csrf-token" content="'.htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8').'"><base href="/social/workspace-test/v1/">', $html);
        $html = str_replace('href="#" aria-label="EP workspace"', 'href="/social/compose#" aria-label="EP workspace"', $html);
        $state = $ghl->state();
        $status = $state['verified'] ? 'EP GHL account access verified · '.$state['account_count'].' accounts. Live calendar and insights connect to Luv, Ep; scheduling requires saved approval.' : 'Verify EP GHL account access in the connection settings. Live sync is not enabled.';
        $html = str_replace('__GHL_ACCOUNT_STATUS__', htmlspecialchars($status, ENT_QUOTES, 'UTF-8'), $html);

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function asset(string $path)
    {
        abort_if(str_contains($path, '..') || str_contains($path, "\0"), 404);
        $root = realpath(Storage::disk('local')->path('social-workspace-test/v1'));
        $file = $root ? realpath($root.'/'.$path) : false;
        abort_unless($root && $file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file), 404);
        $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'js' => 'text/javascript; charset=UTF-8','css' => 'text/css; charset=UTF-8','json' => 'application/json',
            'png' => 'image/png','jpg','jpeg' => 'image/jpeg','webp' => 'image/webp','ttf' => 'font/ttf',
            'txt' => 'text/plain; charset=UTF-8','psd' => 'image/vnd.adobe.photoshop',default => null,
        };
        abort_unless($mime, 404);

        return response()->file($file, ['Content-Type' => $mime, 'Cache-Control' => 'private, max-age=600', 'X-Content-Type-Options' => 'nosniff']);
    }
}
