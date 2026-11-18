<?php

namespace App\Http\Controllers;

use App\Models\EducationRsvp;
use App\Services\EducationRsvpContactUpdate;
use Illuminate\Http\Request;

class EducationRsvpContactController extends Controller
{
    private function authorizeContact(Request $request, EducationRsvp $rsvp, EducationRsvpContactUpdate $service): void
    {
        abort_unless(hash_equals($service->emailKey($rsvp), (string) $request->query('e')), 403);
        abort_unless($service->contactAllowed($rsvp), 410);
    }

    public function show(Request $request, EducationRsvp $rsvp, EducationRsvpContactUpdate $service)
    {
        $this->authorizeContact($request, $rsvp, $service);

        return response()->view('education.rsvp-contact', ['updateUrl' => $request->fullUrl(), 'eventName' => $rsvp->visit->school])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer')->header('X-Frame-Options', 'DENY');
    }

    public function update(Request $request, EducationRsvp $rsvp, EducationRsvpContactUpdate $service)
    {
        $this->authorizeContact($request, $rsvp, $service);
        $data = $request->validate(['phone' => 'required|string|max:40']);
        $service->save($rsvp, (string) $request->query('e'), $data['phone']);

        return redirect()->to($request->fullUrl(), 303)->with('contact_saved', true)
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
