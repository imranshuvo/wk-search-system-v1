<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\SynonymController;
use App\Http\Controllers\Admin\RedirectController;
use App\Http\Controllers\Admin\PinBanController;
use App\Http\Controllers\Admin\AnalyticsController;
use App\Http\Controllers\Admin\SuggestionController;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Controllers\HealthController;

Route::get('/', function () {
    return view('welcome');
});

// Health endpoints
Route::get('/healthz', [HealthController::class, 'healthz']);
Route::get('/readyz', [HealthController::class, 'readyz']);

// Admin auth (PHP only, no JS)
Route::get('/admin/login', [AuthController::class, 'showLogin'])->name('admin.login.form');
Route::post('/admin/login', [AuthController::class, 'login'])->name('admin.login.post');
Route::get('/admin/logout', [AuthController::class, 'logout'])->name('admin.logout');

// Admin tenants
Route::middleware([EnsureAdmin::class])->group(function(){
    Route::get('/admin', [TenantController::class, 'index'])->name('admin.tenants.index');
    Route::get('/admin/tenants', [TenantController::class, 'index'])->name('admin.tenants.index');
    Route::get('/admin/tenants/create', [TenantController::class, 'create'])->name('admin.tenants.create');
    Route::post('/admin/tenants', [TenantController::class, 'store'])->name('admin.tenants.store');
    Route::get('/admin/tenants/{tenant_id}/edit', [TenantController::class, 'edit'])->name('admin.tenants.edit');
    Route::put('/admin/tenants/{tenant_id}', [TenantController::class, 'update'])->name('admin.tenants.update');
    Route::delete('/admin/tenants/{tenant_id}', [TenantController::class, 'destroy'])->name('admin.tenants.destroy');
    Route::post('/admin/tenants/{tenant_id}/regenerate-key', [TenantController::class, 'regenerate'])->name('admin.tenants.regen');
    Route::post('/admin/tenants/{tenant_id}/sync', [TenantController::class, 'sync'])->name('admin.tenants.sync');
    Route::post('/admin/tenants/{tenant_id}/upload', [TenantController::class, 'uploadFeed'])->name('admin.tenants.upload');
    Route::post('/admin/tenants/{tenant_id}/sync-popular', [TenantController::class, 'syncPopularUrl'])->name('admin.tenants.syncPopular');
    Route::post('/admin/tenants/{tenant_id}/sync-top-categories', [TenantController::class, 'syncTopCategoriesUrl'])->name('admin.tenants.syncTopCats');

    // Merchandising
    Route::get('/admin/merch/{tenant_id}/synonyms', [SynonymController::class, 'edit'])->name('admin.synonyms.edit');
    Route::put('/admin/merch/{tenant_id}/synonyms', [SynonymController::class, 'update'])->name('admin.synonyms.update');

    Route::get('/admin/merch/{tenant_id}/redirects', [RedirectController::class, 'index'])->name('admin.redirects.index');
    Route::post('/admin/merch/{tenant_id}/redirects', [RedirectController::class, 'store'])->name('admin.redirects.store');
    Route::put('/admin/merch/{tenant_id}/redirects/{id}', [RedirectController::class, 'update'])->name('admin.redirects.update');
    Route::delete('/admin/merch/{tenant_id}/redirects/{id}', [RedirectController::class, 'destroy'])->name('admin.redirects.destroy');

    Route::get('/admin/merch/{tenant_id}/pins-bans', [PinBanController::class, 'index'])->name('admin.pins_bans.index');
    Route::post('/admin/merch/{tenant_id}/pins', [PinBanController::class, 'addPin'])->name('admin.pins.add');
    Route::delete('/admin/merch/{tenant_id}/pins/{id}', [PinBanController::class, 'removePin'])->name('admin.pins.remove');
    Route::post('/admin/merch/{tenant_id}/bans', [PinBanController::class, 'addBan'])->name('admin.bans.add');
    Route::delete('/admin/merch/{tenant_id}/bans/{id}', [PinBanController::class, 'removeBan'])->name('admin.bans.remove');

    // Analytics
    Route::get('/admin/analytics/{tenant_id}/top-queries', [AnalyticsController::class, 'topQueries'])->name('admin.analytics.top');
    Route::get('/admin/analytics/{tenant_id}/zero-results', [AnalyticsController::class, 'zeroResults'])->name('admin.analytics.zero');
    Route::get('/admin/analytics/{tenant_id}/performance', [AnalyticsController::class, 'performance'])->name('admin.analytics.perf');

    // Synonym suggestions
    Route::get('/admin/merch/{tenant_id}/suggestions', [SuggestionController::class, 'index'])->name('admin.suggestions.index');
    Route::put('/admin/merch/{tenant_id}/suggestions/{id}/approve', [SuggestionController::class, 'approve'])->name('admin.suggestions.approve');
    Route::put('/admin/merch/{tenant_id}/suggestions/{id}/reject', [SuggestionController::class, 'reject'])->name('admin.suggestions.reject');
});
