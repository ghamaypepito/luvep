<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'luvep_hr_test') {
    exit(3);
}
[$script,$operation,$actorId,$subjectId,$gate,$index] = $argv;
config(['hr.enabled' => true, 'hr.notifications_enabled' => false]);
touch($gate.'.ready.'.$index);
$deadline = microtime(true) + 10;
while (! file_exists($gate)) {
    if (microtime(true) > $deadline) {
        exit(4);
    }usleep(10000);
}
try {
    $actor = App\Models\User::findOrFail($actorId);
    if ($operation === 'equipment') {
        app(App\Services\Hr\EquipmentService::class)->issue($actor, App\Models\HrEmployee::findOrFail($subjectId), 'RACE-LAPTOP', 'Synthetic race asset');
    } elseif ($operation === 'leave') {
        app(App\Services\Hr\LeaveService::class)->decide($actor, App\Models\DtrLeave::findOrFail($subjectId), $index === '0' ? 'approved' : 'rejected', 'Concurrent decision', 0);
    } elseif ($operation === 'reminder') {
        $r = Illuminate\Http\Request::create('https://hr.luvep.com/clock/reminder', 'POST');
        $session = $app->make('session')->driver();
        $session->start();
        $r->setLaravelSession($session);
        $r->setUserResolver(fn () => $actor);
        $response = app(App\Http\Controllers\Hr\ReminderController::class)->claim($r);
        echo $response->getData()->notice ? 'claimed' : 'empty';
        exit(0);
    }echo '200';
} catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
    echo $e->getStatusCode();
}
