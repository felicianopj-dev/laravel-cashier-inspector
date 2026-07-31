<?php

use FelicianoPJ\CashierInspector\Http\Controllers\AssetController;
use FelicianoPJ\CashierInspector\Http\Controllers\DashboardController;
use FelicianoPJ\CashierInspector\Http\Controllers\EventDetailController;
use FelicianoPJ\CashierInspector\Http\Controllers\EventPollController;
use FelicianoPJ\CashierInspector\Http\Middleware\Authorize;
use Illuminate\Support\Facades\Route;

Route::group([
    'prefix' => config('cashier-inspector.path'),
    'middleware' => array_merge(config('cashier-inspector.middleware', []), [Authorize::class]),
], function () {
    Route::get('/', [DashboardController::class, 'index'])->name('cashier-inspector.dashboard');
    Route::get('events/{event}', EventDetailController::class)->name('cashier-inspector.events.show');
    Route::get('api/events', EventPollController::class)->name('cashier-inspector.api.events');
    Route::get('assets/alpine.min.js', [AssetController::class, 'alpine'])->name('cashier-inspector.assets.alpine');
});
