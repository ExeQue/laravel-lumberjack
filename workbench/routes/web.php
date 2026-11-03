<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Workbench\App\Http\Controllers\DemoController;

Route::view('/', 'index')->name('home');

Route::post('/demo/exception', [DemoController::class, 'exception'])->name('demo.exception');
Route::post('/demo/queries', [DemoController::class, 'queries'])->name('demo.queries');
Route::post('/demo/slow', [DemoController::class, 'slow'])->name('demo.slow');
Route::post('/demo/log', [DemoController::class, 'log'])->name('demo.log');
Route::post('/demo/job', [DemoController::class, 'job'])->name('demo.job');
Route::post('/demo/failing-job', [DemoController::class, 'failingJob'])->name('demo.failing_job');
Route::post('/demo/spans', [DemoController::class, 'spans'])->name('demo.spans');
Route::post('/demo/exception-as-user', [DemoController::class, 'exceptionAsUser'])->name('demo.exception_as_user');
Route::post('/demo/burst', [DemoController::class, 'burst'])->name('demo.burst');
Route::post('/demo/status/{code}', [DemoController::class, 'status'])->whereNumber('code')->name('demo.status');
Route::post('/demo/command', [DemoController::class, 'command'])->name('demo.command');
Route::post('/demo/release', [DemoController::class, 'release'])->name('demo.release');
Route::post('/demo/health/break', [DemoController::class, 'breakHealth'])->name('demo.break_health');
Route::post('/demo/health/fix', [DemoController::class, 'fixHealth'])->name('demo.fix_health');
Route::post('/demo/health/run', [DemoController::class, 'runHealth'])->name('demo.run_health');
Route::post('/demo/health/report', [DemoController::class, 'reportHealth'])->name('demo.report_health');
Route::post('/demo/rustfs/break', [DemoController::class, 'breakRustfs'])->name('demo.break_rustfs');
Route::post('/demo/rustfs/fix', [DemoController::class, 'fixRustfs'])->name('demo.fix_rustfs');
Route::post('/demo/queue/flood', [DemoController::class, 'floodQueue'])->name('demo.flood_queue');
Route::post('/demo/queue/drain', [DemoController::class, 'drainQueue'])->name('demo.drain_queue');
