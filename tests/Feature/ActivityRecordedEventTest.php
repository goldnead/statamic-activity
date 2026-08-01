<?php

use Goldnead\Activity\Events\ActivityRecorded;
use Goldnead\Activity\Facades\Activity;
use Illuminate\Support\Facades\Event;

it('announces a fact that was written', function (): void {
    Event::fake([ActivityRecorded::class]);

    $activity = Activity::record('commerce.purchase_completed');

    Event::assertDispatched(
        ActivityRecorded::class,
        fn (ActivityRecorded $event) => $event->activity->is($activity)
    );
});

/**
 * A deduplicated write returns the row that already existed. To a read model
 * that is not a new event, and firing again is how it double-counts.
 */
it('stays quiet when the write was deduplicated', function (): void {
    Activity::record('commerce.purchase_completed', ['dedupe_key' => 'order-1']);

    Event::fake([ActivityRecorded::class]);

    Activity::record('commerce.purchase_completed', ['dedupe_key' => 'order-1']);

    Event::assertNotDispatched(ActivityRecorded::class);
});

it('stays quiet when the sanitizer dropped the fact', function (): void {
    Event::fake([ActivityRecorded::class]);

    config()->set('activity.enabled', false);

    Activity::record('commerce.purchase_completed');

    Event::assertNotDispatched(ActivityRecorded::class);
});
