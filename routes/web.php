<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/demo');
Route::inertia('/demo', 'demo')->name('demo');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
