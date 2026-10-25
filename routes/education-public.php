<?php

use App\Http\Controllers\EducationLandingController;
use Illuminate\Support\Facades\Route;

Route::get('/education/register', [EducationLandingController::class, 'show'])->name('education.register');
Route::post('/education/register', [EducationLandingController::class, 'store'])->middleware('throttle:120,1');
Route::post('/education/register/next-step', [EducationLandingController::class, 'prepare'])->middleware('throttle:30,1');
