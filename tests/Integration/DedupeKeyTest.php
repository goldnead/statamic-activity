<?php

use Goldnead\Activity\Models\Activity as ActivityModel;
use Goldnead\Activity\Producers\LeadHubProducer;
use Goldnead\Activity\Producers\MarketingProducer;
use Goldnead\Leadhub\Events\LeadHubStatusChanged;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Marketing\Events\MarketingSubscribed;
use Goldnead\Marketing\Events\MessageSent;
use Goldnead\Marketing\Models\Message;
use Goldnead\Marketing\Models\Subscription;

/**
 * The second half of the index review, and the less visible one.
 *
 * `act_brand_dedupe_unique` is deliberately NULL-permissive: a row without a
 * dedupe key is a fact nobody asked to be deduplicated, held by `event_id`
 * alone. That only works while "no identifier" actually produces NULL. The
 * producers built their keys as `$eventType.':'.($uuid ?? '')`, which turns a
 * missing identifier into a perfectly non-NULL constant — one the unique then
 * binds, collapsing every event of that type in the brand onto the first row
 * ever written.
 *
 * The same oversight as the notifications defect, seen from the other side:
 * there a unique enforced nothing where it should have, here it enforced
 * everything where it should have stood aside. Both come from never deciding
 * what the absent value means.
 *
 * These payloads merge `$event->metadata` last, so the identifier can be
 * blanked through the sibling addons' own public event API.
 */
beforeEach(function (): void {
    MarketingProducer::register(app('activity')->producers());
    LeadHubProducer::register(app('activity')->producers());
});

function dedupeSubscription(string $email): Subscription
{
    return Subscription::create([
        'uuid' => (string) Str::uuid(),
        'list_handle' => 'newsletter',
        'email' => $email,
        'email_normalized' => $email,
        'status' => 'subscribed',
        'source' => 'website',
    ]);
}

function dedupeMessage(string $email): Message
{
    return Message::create([
        'uuid' => (string) Str::uuid(),
        'subscription_id' => dedupeSubscription($email)->id,
        'campaign_handle' => 'welcome',
        'email' => $email,
        'status' => 'sent',
    ]);
}

it('keeps two subscriptions apart when the payload carries no subscription uuid', function (): void {
    MarketingSubscribed::dispatch(dedupeSubscription('a@example.com'), ['subscription_uuid' => null]);
    MarketingSubscribed::dispatch(dedupeSubscription('b@example.com'), ['subscription_uuid' => null]);

    expect(ActivityModel::count())->toBe(2)
        ->and(ActivityModel::pluck('dedupe_key')->unique()->all())->toBe([null]);
});

it('still dedupes a replayed subscription transition when the uuid is there', function (): void {
    // The point is not to stop deduplicating. With the identifier present the
    // key is built and the second dispatch is the same fact as the first.
    $subscription = dedupeSubscription('a@example.com');

    MarketingSubscribed::dispatch($subscription);
    MarketingSubscribed::dispatch($subscription);

    expect(ActivityModel::count())->toBe(1)
        ->and(ActivityModel::first()->dedupe_key)->not->toBeNull();
});

it('keeps two sent messages apart when the payload carries no message uuid', function (): void {
    MessageSent::dispatch(dedupeMessage('a@example.com'), ['message_uuid' => null]);
    MessageSent::dispatch(dedupeMessage('b@example.com'), ['message_uuid' => null]);

    expect(ActivityModel::count())->toBe(2);
});

it('treats an empty leadhub dedupe key as no key at all', function (): void {
    $contact = Contact::create([
        'uuid' => (string) Str::uuid(),
        'email' => 'a@example.com',
        'email_normalized' => 'a@example.com',
        'status' => 'lead',
    ]);

    LeadHubStatusChanged::dispatch($contact, null, ['dedupe_key' => '']);
    LeadHubStatusChanged::dispatch($contact, null, ['dedupe_key' => '']);

    expect(ActivityModel::count())->toBe(2)
        ->and(ActivityModel::pluck('dedupe_key')->unique()->all())->toBe([null]);
});
