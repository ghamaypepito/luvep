<?php

use App\EpAgent\PrivateResponse;
use App\Http\Controllers\Api\EpAgentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', PrivateResponse::class])->prefix('api/ep-agent')->name('ep-agent.')->group(function () {
    Route::get('/admin/usage', \App\Http\Controllers\Admin\EpAgentUsageController::class)->name('admin.usage');
    Route::post('/conversations/{id}/runs', [EpAgentController::class, 'run'])->whereNumber('id')->name('runs.store');
    Route::get('/conversations', [EpAgentController::class, 'index'])->name('conversations.index');
    Route::post('/conversations', [EpAgentController::class, 'store'])->name('conversations.store');
    Route::get('/conversations/{id}', [EpAgentController::class, 'show'])->whereNumber('id')->name('conversations.show');
    Route::post('/conversations/{id}/messages', [EpAgentController::class, 'append'])->whereNumber('id')->name('messages.store');
    Route::delete('/conversations/{id}', [EpAgentController::class, 'destroy'])->whereNumber('id')->name('conversations.archive');
});

Route::middleware(['auth', PrivateResponse::class])->group(function () {
    Route::get('/ep-agent', [\App\Http\Controllers\EpWorkspaceController::class, 'show'])->name('ep-agent.workspace');
    Route::get('/ep-manager', [\App\Http\Controllers\EpWorkspaceController::class, 'manager'])->name('ep-agent.manager');
    Route::get('/api/ep-agent/context', [\App\Http\Controllers\EpWorkspaceController::class, 'context'])->name('ep-agent.context');
    Route::get('/api/ep-agent/manager', [\App\Http\Controllers\Api\EpManagerController::class, 'index'])->name('ep-agent.manager.index');
    Route::get('/api/ep-agent/manager/clients/{id}', [\App\Http\Controllers\Api\EpManagerController::class, 'client'])->whereNumber('id')->name('ep-agent.manager.client');
    Route::get('/api/ep-agent/manager/personnel/{id}', [\App\Http\Controllers\Api\EpManagerController::class, 'personnel'])->whereNumber('id')->name('ep-agent.manager.personnel');
});
