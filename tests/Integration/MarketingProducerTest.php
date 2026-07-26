<?php

use Goldnead\Activity\Models\Activity as ActivityModel;
use Goldnead\Activity\Producers\MarketingProducer;
use Goldnead\IdentityContracts\Identity;
use Goldnead\Marketing\Events\MarketingSubscribed;
use Goldnead\Marketing\Events\MarketingUnsubscribed;
use Goldnead\Marketing\Events\MessageClicked;
use Goldnead\Marketing\Events\MessageOpened;
use Goldnead\Marketing\Events\MessageSent;
use Goldnead\Marketing\Events\SubscriptionPending;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;

beforeEach(function (): void {
    MarketingProducer::register(app('activity')->producers());
});

function subscription(array $attributes = []): Subscription
{
    return Subscription::create(array_merge([
        'uuid' => (string) Str::uuid(),
        'list_handle' => 'newsletter',
        'email' => 'a@example.com',
        'email_normalized' => 'a@example.com',
        'first_name' => 'Adrian',
        'last_name' => 'Goldner',
        'status' => 'subscribed',
        'source' => 'website',
    ], $attributes));
}

function message(): Message
{
    return Message::create([
        'uuid' => (string) Str::uuid(),
        'subscription_id' => subscription()->id,
        'campaign_handle' => 'welcome',
        'email' => 'a@example.com',
        'status' => 'sent',
    ]);
}

it('records a confirmed subscription under the catalogue event type', function (): void {
    MarketingSubscribed::dispatch(subscription(['contact_uuid' => 'c-1']));

    $activity = ActivityModel::first();

    expect(ActivityModel::count())->toBe(1)
        ->and($activity->event_type)->toBe('marketing.subscription_confirmed')
        ->and($activity->actor_type)->toBe(Identity::TYPE_CONTACT)
        ->and($activity->contact_uuid)->toBe('c-1')
        ->and($activity->properties['list'])->toBe('newsletter')
        ->and($activity->subject_type)->toBe(Subscription::class);
});

it('identifies a contact by email when marketing has no crm link yet', function (): void {
    MarketingSubscribed::dispatch(subscription(['contact_uuid' => null]));

    $activity = ActivityModel::first();

    expect($activity->actor_type)->toBe(Identity::TYPE_CONTACT)
        ->and($activity->contact_uuid)->toBeNull()
        // The email must never be pressed into service as an identifier.
        ->and($activity->actor_id)->toBeNull();
});

it('dedupes a replayed subscription transition', function (): void {
    $subscription = subscription();

    MarketingSubscribed::dispatch($subscription);
    MarketingSubscribed::dispatch($subscription);

    expect(ActivityModel::count())->toBe(1);
});

it('keeps pending, confirmed and unsubscribed apart', function (): void {
    $subscription = subscription();

    SubscriptionPending::dispatch($subscription);
    MarketingSubscribed::dispatch($subscription);
    MarketingUnsubscribed::dispatch($subscription);

    expect(ActivityModel::pluck('event_type')->sort()->values()->all())->toBe([
        'marketing.subscription_confirmed',
        'marketing.subscription_pending',
        'marketing.unsubscribed',
    ]);
});

it('treats a repeated open as a second fact but a repeated send as one', function (): void {
    $message = message();

    MessageSent::dispatch($message);
    MessageSent::dispatch($message);

    MessageOpened::dispatch($message);
    MessageOpened::dispatch($message);

    expect(ActivityModel::where('event_type', 'marketing.email_sent')->count())->toBe(1)
        ->and(ActivityModel::where('event_type', 'marketing.email_opened')->count())->toBe(2);
});

it('carries the clicked url into the properties', function (): void {
    $message = message();

    MessageClicked::dispatch($message, ['url' => 'https://example.com/kurs']);

    expect(ActivityModel::where('event_type', 'marketing.email_clicked')->first()->properties['url'])
        ->toBe('https://example.com/kurs');
});
