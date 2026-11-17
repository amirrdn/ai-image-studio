<?php

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
})->name('home');

Route::post('/webhooks/pixelforge', WebhookController::class)
    ->name('webhooks.pixelforge');

Route::get('/dashboard', [GenerationController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::post('/generations/estimate', [GenerationController::class, 'estimate'])
        ->name('generations.estimate');
    Route::post('/generations', [GenerationController::class, 'store'])
        ->name('generations.store');
    Route::post('/generations/{generation}/sync', [GenerationController::class, 'sync'])
        ->name('generations.sync');
    Route::post('/credits/topup', [GenerationController::class, 'addCredits'])
        ->name('credits.topup');

    Route::get('/admin/report', [AdminReportController::class, 'index'])
        ->middleware('admin')
        ->name('admin.report');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
