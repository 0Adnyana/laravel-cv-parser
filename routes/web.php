<?php

use App\Http\Controllers\CvParser\ParseCvController;
use App\Http\Controllers\CvParser\StatusController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/demo')->name('home');
Route::inertia('/demo', 'demo')->name('demo');

Route::prefix('api/v1')->group(function () {
    Route::get('status', StatusController::class);
    Route::post('parse', ParseCvController::class);
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
