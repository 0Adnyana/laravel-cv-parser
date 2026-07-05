<?php

use App\Http\Controllers\Api\V1\ParseCvController;
use App\Http\Controllers\Api\V1\StatusController;
use Illuminate\Support\Facades\Route;

Route::get('status', StatusController::class);
Route::post('parse', ParseCvController::class);
