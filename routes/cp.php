<?php

use Goldnead\Activity\Http\Controllers\Cp\ActivityController;
use Illuminate\Support\Facades\Route;

// The kill switch has to bite here as well as on the nav item. Hiding the entry
// while leaving the screens reachable by URL is not a disabled Control Panel.
if (! config('activity.cp.enabled', true)) {
    return;
}

Route::prefix('activity')->name('activity.')->group(function () {
    Route::get('/', [ActivityController::class, 'index'])->name('index');
    Route::get('/{id}', [ActivityController::class, 'show'])->name('show')->whereNumber('id');
});
