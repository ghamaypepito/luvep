<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'luvep_hr_test') {
    exit(3);
}
[$script, $employeeId, $requestId, $gate, $index] = $argv;
touch($gate.'.ready.'.$index);
$deadline = microtime(true) + 10;
while (! file_exists($gate)) {
    if (microtime(true) > $deadline) {
        exit(4);
    } usleep(10000);
}
try {
    app(App\Services\Hr\ClockService::class)->transition(App\Models\HrEmployee::findOrFail($employeeId), 'clock_in', $requestId, 0);
    echo '200';
} catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
    echo $e->getStatusCode();
}
