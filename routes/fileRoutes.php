<?php

use App\Http\Controllers\FileController;
use App\Http\Controllers\Upload\UploadBatchController;
use App\Http\Controllers\Upload\UploadMultipartController;
use App\Http\Controllers\Upload\UploadPlanController;
use App\Http\Controllers\UserStorage;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('api/storage', UserStorage::class)
        ->middleware('throttle:20,1')
        ->name('api.storage');

    // Step 1: return one upload plan for the complete frontend selection.
    Route::post('/api/uploads/plan', UploadPlanController::class)
        ->name('api.uploads.plan');

    // Step 2A: upload planned small-file batches.
    Route::post('/api/uploads/{uploadId}/batches/{batchId}', [UploadBatchController::class, 'store'])
        ->name('api.uploads.batches.store');

    // Step 2B: upload files with S3 multipart
    Route::post('/api/uploads/{uploadId}/multipart/{uploadFileId}/initiate', [UploadMultipartController::class, 'initiate'])
        ->name('api.uploads.multipart.initiate');

    Route::post('/api/uploads/{uploadId}/multipart/{uploadFileId}/parts/sign', [UploadMultipartController::class, 'sign'])
        ->name('api.uploads.multipart.sign');

    Route::post('/api/uploads/{uploadId}/multipart/{uploadFileId}/complete', [UploadMultipartController::class, 'complete'])
        ->name('api.uploads.multipart.complete');

    Route::post('/api/uploads/{uploadId}/multipart/{uploadFileId}/abort', [UploadMultipartController::class, 'abort'])
        ->name('api.uploads.multipart.abort');
});

Route::controller(FileController::class)
    ->middleware(['auth', 'verified'])->group(function () {
        Route::get('/trash', 'trash')->name('trash');
        Route::post('/folder/create', 'createFolder')->name('folder.create');
        Route::post('/file', 'store')->name('file.store');
        Route::delete('file', 'destroy')->name('file.delete');
        Route::post('/file/restore', 'restore')->name('file.restore');
        Route::delete('/file/destroy', 'deleteForever')->name('file.destroy');
        Route::get('file/download', 'download')->name('file.download');
    });
