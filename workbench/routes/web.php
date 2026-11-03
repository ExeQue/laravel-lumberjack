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
Route::post('/demo/spans', [DemoController::class, 'spans']);
Route::post('/demo/exception-as-user', [DemoController::class, 'exceptionAsUser']);
Route::post('/demo/burst', [DemoController::class, 'burst']);
Route::post('/demo/status/{code}', [DemoController::class, 'status'])->whereNumber('code');
Route::post('/demo/command', [DemoController::class, 'command']);
Route::post('/demo/release', [DemoController::class, 'release']);
Route::post('/demo/health/break', [DemoController::class, 'breakHealth']);
Route::post('/demo/health/fix', [DemoController::class, 'fixHealth']);
Route::post('/demo/health/run', [DemoController::class, 'runHealth']);
Route::post('/demo/health/report', [DemoController::class, 'reportHealth']);
Route::post('/demo/rustfs/break', [DemoController::class, 'breakRustfs']);
Route::post('/demo/rustfs/fix', [DemoController::class, 'fixRustfs']);
Route::post('/demo/queue/flood', [DemoController::class, 'floodQueue']);
Route::post('/demo/queue/drain', [DemoController::class, 'drainQueue']);
