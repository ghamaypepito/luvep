<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', \App\Http\Middleware\EnsureSocialTeamAccess::class])->group(function () {
    Route::post('/admin/content-calendar/generate', [\App\Http\Controllers\Admin\ArticleAssistanceController::class, 'generate'])->middleware('throttle:3,1');
    Route::post('/admin/content-calendar/images', [\App\Http\Controllers\Admin\ArticleAssistanceController::class, 'images'])->middleware('throttle:10,1');
    Route::prefix('admin/content-calendar')->name('admin.editorial.')->controller(\App\Http\Controllers\Admin\EditorialCalendarController::class)->group(function () {
        Route::get('/', 'index')->name('calendar');
        Route::get('/articles/{article}', 'show')->whereNumber('article')->name('show');
        Route::post('/articles', 'store')->name('store');
        Route::put('/articles/{article}', 'update')->whereNumber('article')->name('update');
        Route::patch('/articles/{article}/planned-date', 'move')->whereNumber('article')->name('move');
        Route::post('/articles/{article}/submit', 'submit')->whereNumber('article')->name('submit');
        Route::post('/articles/{article}/approve', 'approve')->whereNumber('article')->name('approve');
        Route::post('/articles/{article}/publish', 'publish')->whereNumber('article')->name('publish');
    });

    Route::get('/social', fn () => redirect('/social/compose'))->name('social');
    Route::get('/social/marketing/posts', [\App\Http\Controllers\Admin\MarketingPostController::class, 'index'])->middleware('throttle:60,1');
    Route::post('/social/marketing/posts', [\App\Http\Controllers\Admin\MarketingPostController::class, 'store'])->middleware('throttle:15,1');
    Route::get('/social/marketing/posts/{id}', [\App\Http\Controllers\Admin\MarketingPostController::class, 'show'])->whereUuid('id')->middleware('throttle:60,1');
    Route::patch('/social/marketing/posts/{id}/planned-date', [\App\Http\Controllers\Admin\MarketingPostController::class, 'move'])->whereUuid('id')->middleware('throttle:15,1');
    Route::get('/social/marketing/posts/{id}/media', [\App\Http\Controllers\Admin\MarketingPostController::class, 'media'])->name('marketing.posts.media');
    Route::post('/social/marketing/posts/{id}/submit', [\App\Http\Controllers\Admin\MarketingPostController::class, 'submit'])->middleware('throttle:15,1');
    Route::post('/social/marketing/posts/{id}/approve', [\App\Http\Controllers\Admin\MarketingPostController::class, 'approve'])->middleware('throttle:15,1');
    Route::post('/social/marketing/posts/{id}/changes', [\App\Http\Controllers\Admin\MarketingPostController::class, 'changes'])->middleware('throttle:15,1');
    Route::post('/social/marketing/posts/{id}/schedule', [\App\Http\Controllers\Admin\MarketingPostController::class, 'schedule'])->middleware('throttle:15,1');
    Route::get('/social/ghl/accounts', [\App\Http\Controllers\Admin\GhlSocialReaderController::class, 'accounts'])->middleware('throttle:30,1');
    Route::get('/social/ghl/posts', [\App\Http\Controllers\Admin\GhlSocialReaderController::class, 'posts'])->middleware('throttle:30,1');
    Route::get('/social/ghl/insights', [\App\Http\Controllers\Admin\GhlSocialReaderController::class, 'insights'])->middleware('throttle:30,1');
    Route::get('/social/compose', [\App\Http\Controllers\Admin\SocialWorkspaceTestController::class, 'index'])->name('social.compose');
    Route::get('/social/workspace-test/v1/{path}', [\App\Http\Controllers\Admin\SocialWorkspaceTestController::class, 'asset'])->where('path', '.*');
    Route::post('/social/workspace-test/ai/caption', \App\Http\Controllers\Admin\SocialCaptionController::class)->middleware('throttle:5,1');
    Route::post('/social/workspace-test/ai/layout', [\App\Http\Controllers\Admin\SocialCaptionController::class, 'layout'])->middleware('throttle:5,1');
    Route::get('/social/drive/assets', [\App\Http\Controllers\SocialDriveController::class, 'index']);
    Route::post('/social/drive/assets', [\App\Http\Controllers\SocialDriveController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/social/drive/assets/{asset}/download', [\App\Http\Controllers\SocialDriveController::class, 'download'])->whereUuid('asset')->name('social.drive.download');
    Route::post('/social/drive/posts/{post}', [\App\Http\Controllers\SocialDriveController::class, 'savePost'])->whereUuid('post')->middleware('throttle:30,1');
});
