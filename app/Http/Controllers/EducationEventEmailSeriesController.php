<?php

namespace App\Http\Controllers;

use App\Services\Ghl\EducationEventEmailSync;
use App\Services\Ghl\GhlConnection;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;

class EducationEventEmailSeriesController extends Controller
{
    public function __invoke()
    {
        $directory = resource_path('education/event-email-series');
        $emails = collect(File::json($directory.'/series.json'))
            ->map(fn (array $email, string $id) => $email + [
                'id' => $id,
                'html' => File::get($directory.'/'.$id.'.html'),
            ])->values();

        return Inertia::render('portal/education/EventEmailSeries', [
            'emails' => $emails,
            'workflowUrl' => 'https://app.gohighlevel.com/v2/location/'.GhlConnection::LOCATION.'/automation/workflow/'.EducationEventEmailSync::NURTURE_WORKFLOW,
        ])->toResponse(request())->header('Cache-Control', 'private, no-store');
    }
}
