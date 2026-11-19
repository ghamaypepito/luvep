<?php

namespace App\Http\Controllers;

use App\Models\EmailBranding;
use App\Models\MessageTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Manage per-department email branding (banner + CTA + button links). Reachable
 * from the admin area and every department portal — the page renders under the
 * matching chrome, and branding is a shared library (like the templates), so any
 * portal staff member can manage it. External leads never reach these routes
 * (portal:* / admin middleware).
 */
class EmailBrandingController extends Controller
{
    /**
     * Resolve the component + base paths from the ROUTE, so the same page renders
     * under admin chrome or the acting portal's chrome.
     *
     * @return array{component: string, basePath: string, templatesPath: string}
     */
    private function context(Request $request): array
    {
        // External partners use approved templates through Compose; staff manage the library.
        abort_if(in_array($request->user()->role, ['agent', \App\Models\User::ROLE_SUB_AGENT], true), 403);

        $name = (string) $request->route()?->getName();

        if (str_starts_with($name, 'portal.')) {
            $dept = explode('.', $name)[1] ?? '';
            if (in_array($dept, MessageTemplate::DEPARTMENTS, true)) {
                return [
                    'component' => "portal/{$dept}/EmailBranding",
                    'basePath' => "/portal/{$dept}/email-branding",
                    'templatesPath' => "/portal/{$dept}/email-templates",
                ];
            }
        }

        return [
            'component' => 'admin/EmailBranding',
            'basePath' => '/admin/email-branding',
            'templatesPath' => '/admin/message-templates',
        ];
    }

    /** The manageable departments (Default + each portal), from the config map. */
    private function departments(): array
    {
        return collect(config('email_branding', []))
            ->map(fn ($cfg, $key) => ['key' => $key, 'label' => $cfg['label'] ?? ucfirst($key)])
            ->values()->all();
    }

    public function index(Request $request)
    {
        $ctx = $this->context($request);

        $rows = EmailBranding::get()->keyBy('department');

        $items = collect($this->departments())->map(function ($d) use ($rows) {
            $assets = EmailBranding::resolveAssets($d['key']);
            $footer = EmailBranding::resolveFooter($d['key']);
            $row = $rows->get($d['key']);

            return [
                'key' => $d['key'],
                'label' => $d['label'],
                'banner_url' => $assets['bannerUrl'],
                'footer_url' => $assets['footerUrl'],
                'has_custom_banner' => (bool) ($row && $row->banner_path),
                'has_custom_footer' => (bool) ($row && $row->footer_path),
                'hide_banner' => (bool) ($row?->hide_banner),
                'hide_footer' => (bool) ($row?->hide_footer),
                'booking_url' => $row?->booking_url ?? '',
                'call_number' => $row?->call_number ?? '',
                'effective_booking_url' => $assets['bookingUrl'],
                'effective_call_number' => $assets['callNumber'],
                // Editable footer text — the department's saved values (blank
                // where not set) plus the effective (resolved) values so the UI
                // can show the placeholder/default it would fall back to.
                'footer_company' => $row?->footer_company ?? '',
                'footer_website_label' => $row?->footer_website_label ?? '',
                'footer_website_url' => $row?->footer_website_url ?? '',
                'footer_email' => $row?->footer_email ?? '',
                'footer_whatsapp' => $row?->footer_whatsapp ?? '',
                'footer_location' => $row?->footer_location ?? '',
                'effective_footer' => $footer,
            ];
        })->values();

        return inertia($ctx['component'], [
            'items' => $items,
            'basePath' => $ctx['basePath'],
            'templatesPath' => $ctx['templatesPath'],
        ]);
    }

    public function update(Request $request, string $department)
    {
        $this->context($request);

        abort_unless(array_key_exists($department, config('email_branding', [])), 404);

        $request->validate([
            'banner' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:4096'],
            'footer' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:4096'],
            'remove_banner' => ['nullable', 'boolean'],
            'remove_footer' => ['nullable', 'boolean'],
            'booking_url' => ['nullable', 'string', 'url', 'max:500'],
            'call_number' => ['nullable', 'string', 'max:40'],
            'hide_banner' => ['nullable', 'boolean'],
            'hide_footer' => ['nullable', 'boolean'],
            // Editable footer text.
            'footer_company' => ['nullable', 'string', 'max:160'],
            'footer_website_label' => ['nullable', 'string', 'max:160'],
            'footer_website_url' => ['nullable', 'string', 'max:500'],
            'footer_email' => ['nullable', 'string', 'max:191'],
            'footer_whatsapp' => ['nullable', 'string', 'max:1000'],
            'footer_location' => ['nullable', 'string', 'max:1000'],
        ]);

        $row = EmailBranding::firstOrNew(['department' => $department]);

        // Footer text — only touch a field that was actually submitted, so a
        // partial save (e.g. an image upload) never wipes the text, and blank
        // clears the override (falls back to the default).
        foreach (['footer_company', 'footer_website_label', 'footer_website_url', 'footer_email', 'footer_whatsapp', 'footer_location'] as $f) {
            if ($request->has($f)) {
                $row->{$f} = $request->input($f) ?: null;
            }
        }

        // CTA button links — only touch a field that was actually submitted, so
        // an image upload doesn't wipe the links and vice-versa.
        if ($request->has('booking_url')) {
            $row->booking_url = $request->input('booking_url') ?: null;
        }
        if ($request->has('call_number')) {
            $row->call_number = $request->input('call_number') ?: null;
        }
        // Hide toggles — turn a banner/CTA off entirely for this department.
        if ($request->has('hide_banner')) {
            $row->hide_banner = $request->boolean('hide_banner');
        }
        if ($request->has('hide_footer')) {
            $row->hide_footer = $request->boolean('hide_footer');
        }

        foreach (['banner', 'footer'] as $field) {
            $col = $field.'_path';
            if ($request->hasFile($field)) {
                if ($row->{$col}) {
                    Storage::disk('public')->delete($row->{$col});
                }
                $row->{$col} = $request->file($field)->store('email-branding', 'public');
            } elseif ($request->boolean('remove_'.$field)) {
                if ($row->{$col}) {
                    Storage::disk('public')->delete($row->{$col});
                }
                $row->{$col} = null;
            }
        }

        $row->save();

        return back()->with('success', ucfirst($department).' email branding updated.');
    }
}
