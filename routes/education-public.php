<?php

use App\Http\Controllers\EducationLandingController;
use Illuminate\Support\Facades\Route;

Route::get('/education/register', [EducationLandingController::class, 'show'])->name('education.register');
Route::post('/education/register', [EducationLandingController::class, 'store'])->middleware('throttle:120,1');
Route::post('/education/register/next-step', [EducationLandingController::class, 'prepare'])->middleware('throttle:30,1');

Route::get('/education/rsvp', [\App\Http\Controllers\EducationRsvpController::class, 'show'])->name('education.rsvp');
Route::get('/education/rsvp/catalogue', [\App\Http\Controllers\EducationRsvpController::class, 'catalogue']);
Route::post('/education/rsvp', [\App\Http\Controllers\EducationRsvpController::class, 'store'])->middleware('throttle:30,1');
Route::post('/education/rsvp/recognize', [\App\Http\Controllers\EducationRsvpController::class, 'recognize'])->middleware('throttle:20,1');
Route::get('/education/rsvp/verify/{rsvp:public_id}', [\App\Http\Controllers\EducationRsvpController::class, 'verify'])->middleware(['signed', 'throttle:30,1'])->name('education.rsvp.verify');
Route::middleware(['auth', 'portal:admin,education'])->group(function () {
    Route::get('/portal/education/rsvps', [\App\Http\Controllers\EducationRsvpAdminController::class, 'index'])->name('education.rsvps.manage');
    Route::get('/portal/education/rsvps/export', [\App\Http\Controllers\EducationAttendanceController::class, 'export'])->name('education.rsvps.export');
    Route::post('/portal/education/rsvps/folders', [\App\Http\Controllers\EducationAttendanceController::class, 'folder']);
    Route::post('/portal/education/rsvps/lists', [\App\Http\Controllers\EducationAttendanceController::class, 'save']);
    Route::post('/portal/education/rsvps/group-members', [\App\Http\Controllers\EducationAttendanceGroupController::class, 'store']);
    Route::delete('/portal/education/rsvps/folders/{folder}/members/{rsvp}', [\App\Http\Controllers\EducationAttendanceGroupController::class, 'destroy']);
    Route::post('/portal/education/rsvps/content/{section}', [\App\Http\Controllers\EducationRsvpAdminController::class, 'content']);
    Route::post('/portal/education/rsvp-visits', [\App\Http\Controllers\EducationRsvpAdminController::class, 'create']);
    Route::put('/portal/education/rsvp-visits/{visit}', [\App\Http\Controllers\EducationRsvpAdminController::class, 'update']);
    Route::post('/portal/education/rsvp-visits/{visit}/logo', [\App\Http\Controllers\EducationRsvpAdminController::class, 'logo']);
    Route::put('/portal/education/rsvps/{rsvp}/attendance', [\App\Http\Controllers\EducationRsvpAdminController::class, 'attendance']);
    Route::post('/portal/education/rsvps/slack', [\App\Http\Controllers\EducationRsvpAdminController::class, 'slack']);
});
