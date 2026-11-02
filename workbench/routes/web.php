<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\DemoController;

Route::view('/', 'index');

Route::post('/demo/exception', [DemoController::class, 'exception']);
Route::post('/demo/queries', [DemoController::class, 'queries']);
Route::post('/demo/slow', [DemoController::class, 'slow']);
Route::post('/demo/log', [DemoController::class, 'log']);
Route::post('/demo/job', [DemoController::class, 'job']);
Route::post('/demo/failing-job', [DemoController::class, 'failingJob']);
