<?php

use Goldnead\Activity\Http\Controllers\Cp\ActivityController;
use Illuminate\Support\Facades\Route;

Route::prefix('activity')->name('activity.')->group(function () {
    Route::get('/', [ActivityController::class, 'index'])->name('index');
    Route::get('/{id}', [ActivityController::class, 'show'])->name('show')->whereNumber('id');
});
