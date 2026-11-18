<?php

use App\Http\Controllers\MediaDriveController;
use App\Http\Controllers\MediaShareController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/media-drive', [MediaDriveController::class, 'entry']);
    Route::get('/portal/lead/media-drive', [MediaDriveController::class, 'clientLinks']);
    Route::get('/media-drive/api/folders/{folder}/activity', [MediaDriveController::class, 'activity']);
    Route::post('/media-drive/api/attach', [MediaDriveController::class, 'attach']);
    Route::get('/media-drive/api/clients', [MediaDriveController::class, 'clients']);
    Route::get('/media-drive/api/folders', [MediaDriveController::class, 'folders']);
    Route::post('/media-drive/api/folders', [MediaDriveController::class, 'createFolder']);
    Route::post('/media-drive/api/folders/{folder}/files', [MediaDriveController::class, 'upload'])->middleware('throttle:60,1,media-upload');
    Route::get('/media-drive/api/files/{file}', [MediaDriveController::class, 'file']);
    Route::get('/media-drive/api/files/{file}/download', [MediaDriveController::class, 'download']);
    Route::get('/media-drive/api/folders/{folder}/shares', [MediaShareController::class, 'index']);
    Route::post('/media-drive/api/folders/{folder}/shares', [MediaShareController::class, 'store']);
    Route::patch('/media-drive/api/shares/{share}', [MediaShareController::class, 'update']);
    Route::delete('/media-drive/api/shares/{share}', [MediaShareController::class, 'revoke']);
    Route::get('/admin/media-drive', [MediaDriveController::class, 'index']);
    Route::get('/portal/{portal}/media-drive', [MediaDriveController::class, 'index'])->whereIn('portal', ['sales', 'education', 'english', 'immigration', 'immigration-adviser', 'accommodation', 'finance', 'agent', 'sub-agent']);
});

Route::prefix('/shared/media/{token}')->middleware('throttle:120,1,media-public')->group(function () {
    Route::get('/', [MediaShareController::class, 'show']);
    Route::post('/code', [MediaShareController::class, 'code'])->middleware('throttle:10,1,media-code');
    Route::post('/verify', [MediaShareController::class, 'verify'])->middleware('throttle:15,1,media-verify');
    Route::get('/contents', [MediaShareController::class, 'contents']);
    Route::get('/files/{file}', [MediaShareController::class, 'download']);
    Route::post('/folders/{folder}/files', [MediaShareController::class, 'upload'])->middleware('throttle:30,1,media-client-upload');
});
