<?php

use Goldnead\Activity\Facades\Activity;
use Goldnead\Activity\Jobs\RecordActivityJob;
use Goldnead\Activity\Models\Activity as ActivityModel;
use Goldnead\Activity\Tests\Fixtures\FakeDomainEvent;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Support\Facades\Queue;

it('records a fact when a registered event is dispatched', function (): void {
    Activity::registerProducer(FakeDomainEvent::class, fn (FakeDomainEvent $event) => [
        'actor' => Identity::contact($event->contactUuid),
        'dedupe_key' => 'fake:'.$event->reference,
        'properties' => ['reference' => $event->reference],
    ], 'commerce.purchase_completed');

    FakeDomainEvent::dispatch('c-1', 'ref-1');

    $activity = ActivityModel::first();

    expect(ActivityModel::count())->toBe(1)
        ->and($activity->event_type)->toBe('commerce.purchase_completed')
        ->and($activity->contact_uuid)->toBe('c-1')
        ->and($activity->properties)->toBe(['reference' => 'ref-1']);
});

it('lets a mapper skip an occurrence by returning null', function (): void {
    Activity::registerProducer(
        FakeDomainEvent::class,
        fn (FakeDomainEvent $event) => $event->reference === 'skip' ? null : ['properties' => []],
        'commerce.purchase_completed',
    );

    FakeDomainEvent::dispatch('c-1', 'skip');
    FakeDomainEvent::dispatch('c-1', 'keep');

    expect(ActivityModel::count())->toBe(1);
});

it('lets a mapper override the event type per occurrence', function (): void {
    Activity::registerProducer(FakeDomainEvent::class, fn (FakeDomainEvent $event) => [
        'event_type' => 'commerce.purchase_'.$event->reference,
    ], 'commerce.purchase_completed');

    FakeDomainEvent::dispatch('c-1', 'refunded');

    expect(ActivityModel::first()->event_type)->toBe('commerce.purchase_refunded');
});

it('ignores events nobody registered', function (): void {
    FakeDomainEvent::dispatch('c-1', 'ref-1');

    expect(ActivityModel::count())->toBe(0);
});

it('dedupes a replayed domain event', function (): void {
    Activity::registerProducer(FakeDomainEvent::class, fn (FakeDomainEvent $event) => [
        'dedupe_key' => 'fake:'.$event->reference,
    ], 'commerce.purchase_completed');

    FakeDomainEvent::dispatch('c-1', 'ref-1');
    FakeDomainEvent::dispatch('c-1', 'ref-1');

    expect(ActivityModel::count())->toBe(1);
});

it('queues the write when queueing is enabled', function (): void {
    Queue::fake();
    config()->set('activity.queue.enabled', true);

    Activity::registerProducer(FakeDomainEvent::class, fn () => [], 'commerce.purchase_completed');

    FakeDomainEvent::dispatch('c-1', 'ref-1');

    Queue::assertPushed(RecordActivityJob::class);
    expect(ActivityModel::count())->toBe(0);
});

it('captures the actor at dispatch time, not in the worker', function (): void {
    Queue::fake();
    config()->set('activity.queue.enabled', true);

    Activity::registerProducer(FakeDomainEvent::class, fn () => [], 'account.login');

    IdentityContext::actingAs(Identity::user(77), fn () => FakeDomainEvent::dispatch('c-1', 'ref-1'));

    Queue::assertPushed(RecordActivityJob::class, function (RecordActivityJob $job): bool {
        return $job->payload['actor']['user_id'] === '77'
            && $job->payload['brand_id'] !== null;
    });
});

it('writes the queued payload unchanged when the job runs', function (): void {
    $data = Activity::hydrate(\Goldnead\Activity\Support\ActivityData::make('account.login', [
        'actor' => Identity::user(88),
        'dedupe_key' => 'login:88',
    ]));

    (new RecordActivityJob($data->toArray()))->handle();

    expect(ActivityModel::count())->toBe(1)
        ->and(ActivityModel::first()->user_id)->toBe('88');
});

it('keys the queued job on the event id so retries collapse', function (): void {
    $job = new RecordActivityJob(['event_id' => 'evt-1', 'event_type' => 'account.login']);

    expect($job->uniqueId())->toBe('evt-1');
});

it('replaces a mapper instead of writing twice when a producer is registered again', function (): void {
    // Regression: a second Event::listen for the same class made every
    // occurrence produce two rows — the shape of an app overriding a bundled
    // mapping.
    Activity::registerProducer(FakeDomainEvent::class, fn () => ['properties' => ['from' => 'first']], 'account.login');
    Activity::registerProducer(FakeDomainEvent::class, fn () => ['properties' => ['from' => 'second']], 'account.login');

    FakeDomainEvent::dispatch('c-1', 'ref-1');

    expect(ActivityModel::count())->toBe(1)
        ->and(ActivityModel::first()->properties)->toBe(['from' => 'second']);
});
