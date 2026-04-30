<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\TenantAuth;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Controllers\Serve\SearchController;
use App\Http\Controllers\Serve\IngestController;
use App\Http\Controllers\Serve\TrackingController;
use App\Http\Controllers\Api\AnalyticsApiController;
use App\Http\Controllers\Api\SuggestionsApiController;
use App\Http\Controllers\Admin\DeltaSyncController;

Route::middleware([CorsMiddleware::class, TenantAuth::class])->group(function(){
    Route::post('/serve/search', [SearchController::class, 'search']);
    Route::post('/serve/suggestions', [SearchController::class, 'suggestions']);
    Route::post('/serve/popular-searches', [SearchController::class, 'popular']);
    Route::post('/serve/popular-queries', [SearchController::class, 'popularQueries']);

    Route::post('/track', [TrackingController::class, 'track']);

    Route::post('/ingest/products', [IngestController::class, 'ingestProducts']);
    Route::post('/ingest/orders', [IngestController::class, 'ingestOrders']);
    Route::post('/ingest/content', [IngestController::class, 'ingestContent']);
    Route::post('/serve/ingest/popular-queries', [IngestController::class, 'ingestPopularQueries']);
    Route::post('/serve/ingest/popular-queries-url', [IngestController::class, 'ingestPopularQueriesUrl']);
    Route::post('/serve/ingest/top-categories-url', [IngestController::class, 'ingestTopCategoriesUrl']);

    // Analytics API for plugin admin
    Route::get('/admin/analytics/top', [AnalyticsApiController::class, 'topQueries']);
    Route::get('/admin/analytics/zero', [AnalyticsApiController::class, 'zeroResults']);
    Route::get('/admin/analytics/perf', [AnalyticsApiController::class, 'performance']);

    // Suggestions API for plugin admin
    Route::get('/admin/suggestions', [SuggestionsApiController::class, 'index']);
    Route::put('/admin/suggestions/{id}/approve', [SuggestionsApiController::class, 'approve']);
    Route::put('/admin/suggestions/{id}/reject', [SuggestionsApiController::class, 'reject']);
    
    // Delta Sync API for real-time product updates
    Route::post('/admin/delta-sync/upsert', [DeltaSyncController::class, 'upsert']);
    Route::delete('/admin/delta-sync/delete/{id}', [DeltaSyncController::class, 'delete']);
    Route::post('/admin/delta-sync/batch', [DeltaSyncController::class, 'batch']);
});
