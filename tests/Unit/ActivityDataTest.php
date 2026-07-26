<?php

use Goldnead\Activity\Support\ActivityData;
use Goldnead\IdentityContracts\Identity;

it('spreads the actor across the dedicated join columns', function (): void {
    $attributes = ActivityData::make('account.login', [
        'actor' => Identity::user(5, 'a@example.com', 'Adrian', 'c-5'),
    ])->toAttributes();

    expect($attributes['actor_type'])->toBe(Identity::TYPE_USER)
        ->and($attributes['actor_id'])->toBe('5')
        ->and($attributes['user_id'])->toBe('5')
        ->and($attributes['contact_uuid'])->toBe('c-5');
});

it('generates an event id when none is supplied', function (): void {
    expect(ActivityData::make('account.login')->toAttributes()['event_id'])->toBeString()->not->toBeEmpty();
});

it('keeps a supplied event id', function (): void {
    expect(ActivityData::make('account.login', ['event_id' => 'evt-1'])->toAttributes()['event_id'])->toBe('evt-1');
});

it('stores empty payloads as null rather than empty json', function (): void {
    $attributes = ActivityData::make('account.login')->toAttributes();

    expect($attributes['properties'])->toBeNull()
        ->and($attributes['context'])->toBeNull();
});

it('survives a queue round trip', function (): void {
    $data = ActivityData::make('commerce.purchase_completed', [
        'actor' => Identity::user(1, 'a@example.com'),
        'brand_id' => 3,
        'event_id' => 'evt-9',
        'dedupe_key' => 'mollie:tr_1',
        'properties' => ['amount' => 4900],
        'context' => ['utm_source' => 'newsletter'],
        'occurred_at' => '2026-02-01 12:00:00',
        'contact_uuid' => 'c-1',
    ]);

    $restored = ActivityData::fromArray($data->toArray());

    // received_at is stamped at write time, so it legitimately differs.
    $strip = fn (array $attributes) => Arr::except($attributes, 'received_at');

    expect($strip($restored->toAttributes()))->toEqual($strip($data->toAttributes()));
});

it('returns copies from the with-modifiers', function (): void {
    $original = ActivityData::make('account.login', ['properties' => ['a' => 1]]);
    $modified = $original->withProperties(['b' => 2]);

    expect($original->properties)->toBe(['a' => 1])
        ->and($modified->properties)->toBe(['b' => 2]);
});

it('rejects a blank event type', function (): void {
    ActivityData::make('   ');
})->throws(InvalidArgumentException::class);
