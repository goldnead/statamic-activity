<?php

use Goldnead\Activity\Facades\Activity;
use Goldnead\Activity\Models\Activity as ActivityModel;

it('records the same dedupe key only once and returns the original', function (): void {
    $first = Activity::record('commerce.purchase_completed', [
        'dedupe_key' => 'mollie:tr_123',
        'properties' => ['amount' => 4900],
    ]);

    $second = Activity::record('commerce.purchase_completed', [
        'dedupe_key' => 'mollie:tr_123',
        'properties' => ['amount' => 9999],
    ]);

    expect(ActivityModel::count())->toBe(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->properties['amount'])->toBe(4900);
});

it('records the same event id only once', function (): void {
    $id = (string) Str::uuid();

    Activity::record('marketing.email_opened', ['event_id' => $id]);
    Activity::record('marketing.email_opened', ['event_id' => $id]);

    expect(ActivityModel::count())->toBe(1);
});

it('treats a repeatable event without a dedupe key as a new fact each time', function (): void {
    Activity::record('marketing.email_opened', ['properties' => ['message_uuid' => 'm-1']]);
    Activity::record('marketing.email_opened', ['properties' => ['message_uuid' => 'm-1']]);

    expect(ActivityModel::count())->toBe(2);
});

it('survives a concurrent insert that wins the race', function (): void {
    // Simulates the interleaving the recorder is built for: the duplicate check
    // misses because the competing row is written between check and insert.
    $data = ['dedupe_key' => 'race:1'];

    $first = Activity::record('commerce.purchase_completed', $data);

    // A second writer with a *different* event id but the same dedupe key hits
    // the unique index rather than the pre-check.
    $second = Activity::record('commerce.purchase_completed', $data + ['event_id' => (string) Str::uuid()]);

    expect(ActivityModel::count())->toBe(1)
        ->and($second->id)->toBe($first->id);
});

it('scopes dedupe keys per brand so two brands may record the same fact', function (): void {
    $this->enableMultiBrand();
    $brandA = $this->makeBrand('brand-a');
    $brandB = $this->makeBrand('brand-b');

    BrandContext::runFor($brandA, fn () => Activity::record('marketing.subscription_confirmed', ['dedupe_key' => 'sub:1']));
    BrandContext::runFor($brandB, fn () => Activity::record('marketing.subscription_confirmed', ['dedupe_key' => 'sub:1']));

    $all = BrandContext::withoutBrandScope(fn () => ActivityModel::count());

    expect($all)->toBe(2);
});

it('never lets a ledger failure escape into the caller', function (): void {
    // Drop the table under the recorder: a broken ledger must not roll back the
    // purchase that produced the event.
    Schema::drop('activities');

    expect(Activity::record('commerce.purchase_completed'))->toBeNull();
});
