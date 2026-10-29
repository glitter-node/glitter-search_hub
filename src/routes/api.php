<?php

use Illuminate\Support\Facades\Route;
use Modules\Glitter\SearchHub\Http\Controllers\Admin\SearchHubController;
use Modules\Glitter\SearchHub\Http\Controllers\PublicSearchHubController;

Route::get('providers', [PublicSearchHubController::class, 'providers'])
    ->middleware('throttle:60,1')
    ->name('providers.public');

Route::prefix('admin/search-hub')->middleware(['auth:sanctum', 'throttle:120,1'])->name('admin.search-hub.')->group(function () {
    Route::get('/dashboard', [SearchHubController::class, 'dashboard'])->middleware('permission:admin,glitter-search_hub.search_hub.read')->name('dashboard');
    Route::get('/providers', [SearchHubController::class, 'providers'])->middleware('permission:admin,glitter-search_hub.search_hub.read')->name('providers.index');
    Route::get('/providers/discovery', [SearchHubController::class, 'discoverEndpoints'])->middleware('permission:admin,glitter-search_hub.search_hub.read')->name('providers.discovery');
    Route::post('/providers/discovery/{candidate}', [SearchHubController::class, 'registerEndpoint'])->middleware('permission:admin,glitter-search_hub.search_hub.update')->name('providers.discovery.register');
    Route::put('/providers/{provider}', [SearchHubController::class, 'updateProvider'])->middleware('permission:admin,glitter-search_hub.search_hub.update')->name('providers.update');
    Route::get('/analytics', [SearchHubController::class, 'analytics'])->middleware('permission:admin,glitter-search_hub.search_hub.read')->name('analytics.index');
    Route::get('/diagnostics', [SearchHubController::class, 'diagnostics'])->middleware('permission:admin,glitter-search_hub.search_hub.read')->name('diagnostics');
    Route::get('/settings', [SearchHubController::class, 'settings'])->middleware('permission:admin,glitter-search_hub.search_hub.read')->name('settings');
});
