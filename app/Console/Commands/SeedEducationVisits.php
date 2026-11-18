<?php

namespace App\Console\Commands;

use App\Models\EducationVisit;
use App\Models\Setting;
use Illuminate\Console\Command;

class SeedEducationVisits extends Command
{
    protected $signature = 'education:seed-rsvp-visits';

    protected $description = 'Create the approved November school/partnership RSVP visits without confirming or overwriting schedules';

    public function handle(): int
    {
        $rows = [['smct', 'St. Mary’s College of Tagum', 'Tagum City', '2026-11-05', ['school' => 'Career guidance', 'partnership' => 'Partnership meeting']], ['tccst', 'Tagum City College of Science and Technology Foundation, Inc.', 'Tagum City', '2026-11-05', ['school' => 'Career guidance', 'partnership' => 'Partnership meeting']], ['brokenshire', 'Brokenshire College of SOCCSKSARGEN', 'Online session', '2026-11-05', ['partnership' => 'Online partnership meeting']], ['jmc', 'Jose Maria College Foundation, Inc.', 'Davao City', '2026-11-06', ['partnership' => 'Partnership meeting']], ['nu-davao', 'National University – Davao', 'Davao City', '2026-11-06', ['partnership' => 'Partnership meeting']], ['um-digos', 'UM Digos College', 'Digos City', '2026-11-07', ['school' => 'Graduate student meet-up']], ['nu-clark', 'National University – Clark', 'Clark', '2026-11-09', ['school' => 'School visit']]];
        foreach ($rows as [$slug,$school,$city,$date,$kinds]) {
            $kind = array_key_exists('school', $kinds) ? 'school' : 'partnership';
            $activity = $kinds[$kind];
            EducationVisit::firstOrCreate(['slug' => $slug], ['school' => $school, 'city' => $city, 'kind' => $kind, 'activity' => 'Education event', 'proposed_date' => $date, 'mode' => $slug === 'brokenshire' ? 'online' : 'in_person', 'logo_path' => $slug === 'brokenshire' ? '/education-rsvp/assets/brokenshire-logo.png' : null]);
        }if (! Setting::get('education_slack_channel_id')) {
            Setting::set('education_slack_channel_id', 'C0CAUU9RAQJ');
        }$this->info('RSVP visits ready; no schedule was confirmed or overwritten.');

        return self::SUCCESS;
    }
}
