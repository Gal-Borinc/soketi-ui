<?php

use App\Http\Controllers\AppsController;
use App\Http\Controllers\DebugController;
use App\Http\Controllers\LimitsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WebhooksController;
use App\Http\Controllers\SoketiMetricsController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', fn () => redirect('/login'));

// Webhook routes removed - using direct metrics scraping instead

Route::middleware(['auth', 'verified'])->prefix('apps')->name('apps.')->group(function () {
    Route::get('/', [AppsController::class, 'index'])->name('index');
    Route::post('/create', [AppsController::class, 'create'])->name('create');

    Route::prefix('{app}')->group(function () {
        Route::get('debug', [DebugController::class, 'index'])->name('debug');
// Debug webhook toggle removed - using direct metrics instead

        // ───────────────────────────────────────── Soketi Metrics (Direct Scraping)
        Route::get('metrics', [SoketiMetricsController::class, 'page'])->name('metrics');
        Route::prefix('metrics')->name('metrics.')->group(function () {
            Route::get('cached', [SoketiMetricsController::class, 'getCachedMetrics'])->name('cached');
            Route::get('timeseries', [SoketiMetricsController::class, 'getTimeSeriesData'])->name('timeseries');
            Route::get('health', [SoketiMetricsController::class, 'getSoketiHealth'])->name('health');
            Route::post('refresh', [SoketiMetricsController::class, 'refreshMetrics'])->name('refresh');
        });

        Route::prefix('webhooks')->name('webhooks.')->group(function () {
            Route::post('save', [WebhooksController::class, 'save']);
            Route::post('delete', [WebhooksController::class, 'delete']);
        });

        Route::post('limits', [LimitsController::class, 'save'])->name('limits');

        Route::post('refresh-credentials', [AppsController::class, 'refreshCredentials'])->name('refresh-credentials');
    });
});



Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
