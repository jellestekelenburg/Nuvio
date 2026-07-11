<?php

use App\Http\Controllers\FileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::controller(FileController::class)
    ->middleware(['auth', 'verified'])->group(function () {
        Route::get('/my-files/{folder?}', 'myFiles')
            ->where('folder', '(.*)')
            ->name('myFiles');
    });

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

require __DIR__.'/settings.php';
require __DIR__.'/fileRoutes.php';
