<?php

// Synthetic browser fixtures. Never run against an application database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$isolatedDatabase = config('database.default') === 'sqlite'
    || (config('database.default') === 'mysql' && config('database.connections.mysql.database') === 'luvep_hr_acceptance_20261019');
if (getenv('HR_E2E') !== '1' || ! $isolatedDatabase || ! str_ends_with(config('hr.host'), '.test') || config('app.env') !== 'local') {
    throw new RuntimeException('HR browser fixtures require the isolated local test environment.');
}
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
$user = App\Models\User::create(['name' => 'Alex Rivera', 'email' => 'alex@example.test', 'password' => 'Browser-Test-123', 'role' => 'sales', 'is_active' => true]);
$admin = App\Models\User::create(['name' => 'HR Administrator', 'email' => 'admin@example.test', 'password' => 'Browser-Test-123', 'role' => 'super_admin', 'is_active' => true]);
$employee = App\Models\HrEmployee::create(['user_id' => $user->id, 'status' => 'active', 'department' => 'Operations', 'position' => 'Coordinator', 'manager_user_id' => $admin->id, 'reviewer_user_id' => $admin->id]);
App\Models\DtrSetting::create(['user_id' => $user->id, 'timezone' => 'Asia/Manila', 'sched_in' => '08:00', 'sched_out' => '17:00', 'schedule_type' => 'fixed', 'std_hours' => 8, 'is_complete' => true]);
$second = App\Models\User::create(['name' => 'Sam Lee', 'email' => 'sam@example.test', 'password' => 'Browser-Test-123', 'role' => 'sales', 'is_active' => true]);
App\Models\HrEmployee::create(['user_id' => $second->id, 'status' => 'active', 'department' => 'Operations', 'manager_user_id' => $admin->id, 'reviewer_user_id' => $admin->id]);
$journey = App\Models\User::create(['name' => 'Taylor Morgan', 'email' => 'taylor@example.test', 'password' => 'Browser-Test-123', 'role' => 'sales', 'is_active' => true]);
$je = App\Models\HrEmployee::create(['user_id' => $journey->id, 'status' => 'active', 'department' => 'Operations', 'position' => 'Coordinator', 'manager_user_id' => $admin->id, 'reviewer_user_id' => $admin->id]);
App\Models\DtrSetting::create(['user_id' => $journey->id, 'timezone' => 'Asia/Manila', 'team' => 'Philippines', 'sched_in' => '08:00', 'sched_out' => '17:00', 'schedule_type' => 'fixed', 'std_hours' => 8, 'is_complete' => true]);
$day = now('Asia/Manila')->subDays(7)->toDateString();
$entry = App\Models\DtrEntry::create(['user_id' => $journey->id, 'work_date' => $day, 'time_in' => '08:00', 'time_out' => '17:00', 'tasks' => []]);
$entry->forceFill(['hr_status' => 'returned', 'time_source' => 'live', 'hr_version' => 1])->save();
$shift = App\Models\HrShift::create(['hr_employee_id' => $je->id, 'work_date' => $day, 'timezone' => 'Asia/Manila', 'active_guard' => null, 'started_at' => Carbon\CarbonImmutable::parse($day.' 08:00', 'Asia/Manila')->utc(), 'ended_at' => Carbon\CarbonImmutable::parse($day.' 17:00', 'Asia/Manila')->utc()]);
$shift->breaks()->create(['started_at' => Carbon\CarbonImmutable::parse($day.' 12:00', 'Asia/Manila')->utc(), 'ended_at' => Carbon\CarbonImmutable::parse($day.' 12:30', 'Asia/Manila')->utc()]);
$template = app(App\Services\Hr\ChecklistService::class)->saveTemplate($admin, ['name' => 'Welcome checklist', 'kind' => 'onboarding', 'version' => 0, 'tasks' => [['title' => 'Confirm your welcome pack', 'kind' => 'general', 'owner_user_id' => $journey->id, 'employee_visible' => true, 'note' => 'Read your introduction to the team.', 'private_note' => 'PRIVATE ONBOARDING NOTE']]]);
app(App\Services\Hr\ChecklistService::class)->start($admin, $je, $template, now()->addWeek()->toDateString());
$retry = App\Models\User::create(['name' => 'Clock Retry Tester', 'email' => 'retry@example.test', 'password' => 'Browser-Test-123', 'role' => 'sales', 'is_active' => true]);
App\Models\HrEmployee::create(['user_id' => $retry->id, 'status' => 'active', 'manager_user_id' => $admin->id, 'reviewer_user_id' => $admin->id]);
App\Models\DtrSetting::create(['user_id' => $retry->id, 'timezone' => 'Asia/Manila', 'sched_in' => '08:00', 'sched_out' => '17:00', 'is_complete' => true, 'schedule_type' => 'fixed']);
$reminder = App\Models\User::create(['name' => 'Reminder Tester', 'email' => 'reminder@example.test', 'password' => 'Browser-Test-123', 'role' => 'sales', 'is_active' => true]);
$re = App\Models\HrEmployee::create(['user_id' => $reminder->id, 'status' => 'active', 'manager_user_id' => $admin->id, 'reviewer_user_id' => $admin->id]);
App\Models\DtrSetting::create(['user_id' => $reminder->id, 'timezone' => 'Asia/Manila', 'sched_in' => '08:00', 'sched_out' => '17:00', 'is_complete' => true, 'schedule_type' => 'fixed']);
App\Models\HrShift::create(['hr_employee_id' => $re->id, 'work_date' => now('Asia/Manila')->toDateString(), 'timezone' => 'Asia/Manila', 'started_at' => now()->subHours(7), 'scheduled_end_at' => now()->addMinutes(14), 'active_guard' => 1]);
$session = app('session')->driver();
$session->start();
$session->put(Illuminate\Support\Facades\Auth::guard()->getName(), $user->id);
$session->put('password_hash_web', $user->getAuthPassword());
$session->save();
$name = config('session.cookie');
$value = Illuminate\Cookie\CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId();

$commentLead = App\Models\Lead::create(['first_name' => 'Synthetic', 'last_name' => 'Comment client']);
App\Models\LeadTask::create(['lead_id' => $commentLead->id, 'title' => 'Comment CSRF regression', 'created_by' => $admin->id, 'assignee_id' => $admin->id, 'department' => 'admin']);

$ageLead = App\Models\Lead::create(['first_name' => 'Age', 'last_name' => 'Verification']);
$ageLead->forceFill(['age' => 37])->save();

App\Models\Lead::create(['first_name' => 'Chart', 'last_name' => 'Education', 'is_student' => true, 'education_stage' => 'Endorsed to School']);
App\Models\Lead::create(['first_name' => 'Chart', 'last_name' => 'English', 'is_english_student' => true, 'english_stage' => 'PTE Review']);
App\Models\Lead::create(['first_name' => 'Chart', 'last_name' => 'Immigration', 'is_student' => true, 'is_immigration_case' => true, 'inz_visa_type' => 'Student Visa', 'immigration_stage' => 'Interim Visa Issued']);

// Isolated fixtures for notes, recruiting-agent assignment and school entry.
$notesAgent = App\Models\User::create(['name' => 'Notes Recruiting Agent', 'email' => 'notes-agent@example.test', 'password' => 'Browser-Test-123', 'role' => 'agent', 'is_active' => true]);
$otherNotesAgent = App\Models\User::create(['name' => 'Other Notes Agent', 'email' => 'other-notes-agent@example.test', 'password' => 'Browser-Test-123', 'role' => 'agent', 'is_active' => true]);
App\Models\Lead::create(['first_name' => 'Bulk', 'last_name' => 'Alpha']);
App\Models\Lead::create(['first_name' => 'Bulk', 'last_name' => 'Beta']);
App\Models\Lead::create(['first_name' => 'Private', 'last_name' => 'Other Referral', 'agent_id' => $otherNotesAgent->id]);
$notesSchool = App\Models\School::create(['name' => 'Browser Academy', 'status' => 'active']);
foreach (['Browser Nursing', 'Browser Business'] as $title) {
    App\Models\Program::create(['title' => $title, 'slug' => Illuminate\Support\Str::slug($title), 'level' => 7, 'category' => 'bachelors', 'status' => 'published', 'school_id' => $notesSchool->id]);
}
$schoolLead = App\Models\Lead::create(['first_name' => 'School', 'last_name' => 'Verification', 'is_student' => true, 'student_school' => 'Browser Academy']);
$schoolLead->studyPlans()->create(['qualification_level' => 'Bachelor', 'preferred_course' => 'Browser Nursing', 'program_schools' => ['Browser Academy']]);
$notesLead = App\Models\Lead::create(['first_name' => 'Notes', 'last_name' => 'Verification', 'tracking_code' => 'EP-NOTESBROWSER']);
$legacyDoc = App\Models\LeadDocument::create(['lead_id' => $notesLead->id, 'checklist_key' => 'agree.proposal', 'original_name' => 'Historical study proposal.pdf', 'file_path' => 'browser-fixtures/historical-proposal.pdf', 'source' => 'upload', 'status' => 'Submitted']);
Illuminate\Support\Facades\Storage::disk('local')->put($legacyDoc->file_path, "%PDF-1.4\n%%EOF");

// Reporting fixtures contain synthetic identities only.
foreach (['Approved Visa' => 'Approved', 'Interim Visa Issued' => 'Interim', 'Request for Information' => 'RFI', 'Decline Visa' => 'Declined'] as $stage => $label) {
    App\Models\Lead::create(['first_name' => 'Report', 'last_name' => $label, 'is_immigration_case' => true,
        'inz_visa_type' => 'Student Visa', 'immigration_stage' => $stage,
        'immigration_converted_at' => now(), 'stage_updated_at' => now(),
        'stage_history' => [['department' => 'immigration', 'stage' => 'Endorsed', 'at' => now()->toIso8601String()]]]);
}

$aliasVisa = App\Models\VisaType::updateOrCreate(['code' => 'PRSWV'], ['name' => 'Partner of a Student Work Visa', 'category' => 'Partner', 'active' => true, 'consultation_price_nzd' => 250,
    'checklist_items' => [
        ['key' => 'sponsor_nz_visa', 'label' => 'Sponsor NZ Visa', 'category' => 'Sponsor Documents', 'required' => true],
        ['key' => 'nz_visa', 'label' => 'NZ Visa of Partner', 'category' => 'Sponsor Documents', 'required' => true],
    ]]);
$aliasLead = App\Models\Lead::create(['first_name' => 'Alias', 'last_name' => 'Verification', 'is_immigration_case' => true, 'inz_visa_type' => 'PRSWV']);
App\Models\User::create(['name' => 'Alias Client', 'email' => 'alias-client@example.test', 'password' => 'Browser-Test-123', 'role' => 'lead', 'lead_id' => $aliasLead->id, 'is_active' => true]);
foreach (['sponsor_nz_visa' => 'Original sponsor visa.pdf', 'nz_visa' => 'Historical partner visa.pdf'] as $key => $name) {
    App\Models\LeadDocument::create(['lead_id' => $aliasLead->id, 'checklist_key' => $key, 'original_name' => $name,
        'file_path' => 'browser-fixtures/'.$key.'.pdf', 'source' => 'upload', 'status' => 'Submitted']);
    Illuminate\Support\Facades\Storage::disk('local')->put('browser-fixtures/'.$key.'.pdf', "%PDF-1.4\n%%EOF");
}

// Signal readiness only after every synthetic fixture succeeds.
file_put_contents(getenv('HR_FIXTURE_FILE'), json_encode(['employeeId' => $employee->id, 'legacyCookie' => ['name' => $name, 'value' => app('encrypter')->encrypt($value, false)]]));
chmod(getenv('HR_FIXTURE_FILE'), 0600);
