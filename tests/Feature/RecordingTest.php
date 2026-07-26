<?php

use Goldnead\Activity\Facades\Activity;
use Goldnead\Activity\Models\Activity as ActivityModel;
use Goldnead\IdentityContracts\Facades\IdentityContext;
use Goldnead\IdentityContracts\Identity;

it('records a fact with the columns filled from the identity', function (): void {
    $activity = Activity::record('commerce.purchase_completed', [
        'actor' => Identity::user(42, 'a@example.com', 'Adrian', 'c-42'),
        'properties' => ['product' => 'stimmnotfallplan', 'amount' => 4900, 'currency' => 'EUR'],
    ]);

    expect($activity)->not->toBeNull()
        ->and($activity->event_type)->toBe('commerce.purchase_completed')
        ->and($activity->actor_type)->toBe(Identity::TYPE_USER)
        ->and($activity->actor_id)->toBe('42')
        ->and($activity->user_id)->toBe('42')
        ->and($activity->contact_uuid)->toBe('c-42')
        ->and($activity->properties)->toBe(['product' => 'stimmnotfallplan', 'amount' => 4900, 'currency' => 'EUR'])
        ->and($activity->source)->toBe('test-suite')
        ->and($activity->event_id)->not->toBeNull()
        ->and($activity->occurred_at)->not->toBeNull()
        ->and($activity->received_at)->not->toBeNull();
});

it('stamps the default brand when none is current', function (): void {
    $activity = Activity::record('account.login');

    expect($activity->brand_id)->toBe(app('brand-context')->defaultId());
});

it('falls back to the ambient identity when no actor is passed', function (): void {
    $actor = Identity::user(7, 'b@example.com');

    $activity = IdentityContext::actingAs($actor, fn () => Activity::record('resource.downloaded'));

    expect($activity->user_id)->toBe('7');
});

it('accepts an explicit occurred_at and keeps received_at as now', function (): void {
    $activity = Activity::record('commerce.purchase_completed', [
        'occurred_at' => '2026-01-15 10:00:00',
    ]);

    expect($activity->occurred_at->format('Y-m-d H:i:s'))->toBe('2026-01-15 10:00:00')
        ->and($activity->received_at->isToday())->toBeTrue();
});

it('derives the subject from a model', function (): void {
    $other = Activity::record('first.event');

    $activity = Activity::record('second.event', ['subject' => $other]);

    expect($activity->subject_type)->toBe(ActivityModel::class)
        ->and($activity->subject_id)->toBe((string) $other->id);
});

it('lets an explicit join key override the actor', function (): void {
    // A CP user changing a contact's status: the actor is the operator, the
    // record is about the contact.
    $activity = Activity::record('crm.status_changed', [
        'actor' => Identity::user(1, 'operator@example.com'),
        'contact_uuid' => 'c-999',
    ]);

    expect($activity->user_id)->toBe('1')
        ->and($activity->contact_uuid)->toBe('c-999');
});

it('writes nothing when the ledger is disabled', function (): void {
    config()->set('activity.enabled', false);

    expect(Activity::record('account.login'))->toBeNull()
        ->and(ActivityModel::count())->toBe(0);
});

it('rejects an empty event type', function (): void {
    Activity::record('  ');
})->throws(InvalidArgumentException::class);
