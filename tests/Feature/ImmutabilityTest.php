<?php

use Goldnead\Activity\Exceptions\ImmutableActivity;
use Goldnead\Activity\Facades\Activity;
use Goldnead\Activity\Models\Activity as ActivityModel;

it('refuses to update a stored fact', function (): void {
    $activity = Activity::record('commerce.purchase_completed');

    $activity->event_type = 'commerce.purchase_refunded';
    $activity->save();
})->throws(ImmutableActivity::class);

it('refuses to delete a stored fact', function (): void {
    Activity::record('commerce.purchase_completed')->delete();
})->throws(ImmutableActivity::class);

it('allows the retention path to delete', function (): void {
    Activity::record('commerce.purchase_completed');

    ActivityModel::mutable(fn () => ActivityModel::query()->delete());

    expect(ActivityModel::count())->toBe(0);
});

it('restores the guard after the retention path finishes', function (): void {
    $activity = Activity::record('commerce.purchase_completed');

    ActivityModel::mutable(fn () => null);

    expect(fn () => $activity->delete())->toThrow(ImmutableActivity::class);
});

it('restores the guard even when the retention path throws', function (): void {
    $activity = Activity::record('commerce.purchase_completed');

    try {
        ActivityModel::mutable(function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(fn () => $activity->delete())->toThrow(ImmutableActivity::class);
});

it('refuses a mass update through the query builder', function (): void {
    // Regression: model events only fire for instance operations, so this path
    // rewrote the ledger unchallenged while $activity->save() was blocked. The
    // guard covered the polite path and missed the fast one.
    Activity::record('commerce.purchase_completed');

    ActivityModel::query()->update(['event_type' => 'tampered']);
})->throws(ImmutableActivity::class);

it('refuses a mass delete through the query builder', function (): void {
    Activity::record('commerce.purchase_completed');

    ActivityModel::query()->delete();
})->throws(ImmutableActivity::class);

it('refuses a mass update even without the global scopes', function (): void {
    Activity::record('commerce.purchase_completed');

    ActivityModel::withoutGlobalScopes()->update(['event_type' => 'tampered']);
})->throws(ImmutableActivity::class);

it('still lets retention and anonymisation through', function (): void {
    Activity::record('commerce.purchase_completed');

    ActivityModel::mutable(fn () => ActivityModel::query()->update(['anonymized' => true]));
    expect(ActivityModel::first()->anonymized)->toBeTrue();

    ActivityModel::mutable(fn () => ActivityModel::query()->delete());
    expect(ActivityModel::count())->toBe(0);
});
