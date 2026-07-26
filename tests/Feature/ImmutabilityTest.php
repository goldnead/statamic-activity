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
