<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Hr\DashboardController;
use App\Http\Controllers\Hr\EmployeeController;
use App\Http\Controllers\Hr\PreviewController;
use App\Http\Middleware\EnsureHrAccess;
use Illuminate\Support\Facades\Route;

$hrRoutes = function () {
    Route::middleware(['auth', EnsureHrAccess::class])->group(function () {
        Route::get('/dtr/report', \App\Http\Controllers\Hr\DtrReportController::class)->name('dtr.report');
        Route::get('/dtr', [\App\Http\Controllers\Hr\DtrController::class, 'index'])->name('dtr');
        Route::get('/dtr/review', [\App\Http\Controllers\Hr\DtrController::class, 'inbox'])->name('dtr.inbox');
        Route::post('/dtr', [\App\Http\Controllers\Hr\DtrController::class, 'store'])->name('dtr.store');
        Route::post('/dtr/week/sign', [\App\Http\Controllers\Hr\DtrController::class, 'signWeek'])->name('dtr.week.sign');
        Route::post('/dtr/week/{week}/review', [\App\Http\Controllers\Hr\DtrController::class, 'reviewWeek'])->name('dtr.week.review');
        Route::post('/dtr/{entry}/submit', [\App\Http\Controllers\Hr\DtrController::class, 'submit'])->name('dtr.submit');
        Route::post('/dtr/{entry}/review', [\App\Http\Controllers\Hr\DtrController::class, 'review'])->name('dtr.review');
        Route::post('/dtr/{entry}/correct', [\App\Http\Controllers\Hr\DtrController::class, 'correct'])->name('dtr.correct');
        Route::get('/clock', [\App\Http\Controllers\Hr\ClockController::class, 'show'])->name('clock');
        Route::post('/clock', [\App\Http\Controllers\Hr\ClockController::class, 'store'])->name('clock.store');
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/profile', [EmployeeController::class, 'profile'])->name('profile');
        Route::patch('/profile', [EmployeeController::class, 'update'])->name('profile.update');
        Route::get('/people', [EmployeeController::class, 'index'])->name('people');
        Route::get('/people/{employee}', [EmployeeController::class, 'show'])->name('people.show');
        Route::get('/preview', [PreviewController::class, 'index'])->name('preview');
        Route::post('/preview/exit', [PreviewController::class, 'destroy'])->name('preview.exit');
        Route::post('/preview/{employee}', [PreviewController::class, 'store'])->name('preview.start');
    });
};
Route::domain(config('hr.host'))->name('hr.')->group(function () use ($hrRoutes) {
    $hrRoutes();
    Route::get('/login', fn () => redirect()->away(config('hr.main_url').'/login'))->name('login');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::any('/{path?}', fn () => abort(404))->where('path', '.*')->name('not-found');
});
Route::prefix('hr')->name('hr.main.')->group($hrRoutes);
