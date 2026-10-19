<?php

// Synthetic local-only browser fixtures. Never run against a deployed database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (getenv('HR_E2E') !== '1' || config('database.default') !== 'sqlite' || ! str_ends_with(config('hr.host'), '.test')) {
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
$shift = App\Models\HrShift::create(['hr_employee_id' => $je->id, 'work_date' => $day, 'timezone' => 'Asia/Manila', 'started_at' => Carbon\CarbonImmutable::parse($day.' 08:00', 'Asia/Manila')->utc(), 'ended_at' => Carbon\CarbonImmutable::parse($day.' 17:00', 'Asia/Manila')->utc()]);
$shift->breaks()->create(['started_at' => Carbon\CarbonImmutable::parse($day.' 12:00', 'Asia/Manila')->utc(), 'ended_at' => Carbon\CarbonImmutable::parse($day.' 12:30', 'Asia/Manila')->utc()]);
$template = app(App\Services\Hr\ChecklistService::class)->saveTemplate($admin, ['name' => 'Welcome checklist', 'kind' => 'onboarding', 'version' => 0, 'tasks' => [['title' => 'Confirm your welcome pack', 'kind' => 'general', 'owner_user_id' => $journey->id, 'employee_visible' => true, 'note' => 'Read your introduction to the team.', 'private_note' => 'PRIVATE ONBOARDING NOTE']]]);
app(App\Services\Hr\ChecklistService::class)->start($admin, $je, $template, now()->addWeek()->toDateString());
$session = app('session')->driver();
$session->start();
$session->put(Illuminate\Support\Facades\Auth::guard()->getName(), $user->id);
$session->put('password_hash_web', $user->getAuthPassword());
$session->save();
$name = config('session.cookie');
$value = Illuminate\Cookie\CookieValuePrefix::create($name, app('encrypter')->getKey()).$session->getId();
file_put_contents(getenv('HR_FIXTURE_FILE'), json_encode(['employeeId' => $employee->id, 'legacyCookie' => ['name' => $name, 'value' => app('encrypter')->encrypt($value, false)]]));
chmod(getenv('HR_FIXTURE_FILE'), 0600);
