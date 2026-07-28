<?php

namespace Goldnead\Activity\Producers;

use Goldnead\IdentityContracts\Identity;

/**
 * Bundled producer for `goldnead/statamic-marketing`. Registers itself only when
 * that addon is installed, so the dependency stays one-directional and optional.
 *
 * Event type naming follows the platform catalogue (`marketing.email_opened`,
 * not the PHP class name) so that consumers are insulated from class renames.
 */
class MarketingProducer
{
    /**
     * Events that describe a state transition get a dedupe key — recording the
     * same transition twice is always wrong. Repeatable events (an open, a click)
     * deliberately get none: the second open is a second fact, and idempotency
     * there rests on `event_id` alone.
     */
    public static function register(ProducerRegistry $registry): void
    {
        $subscription = [
            \Goldnead\Marketing\Events\SubscriptionPending::class => 'marketing.subscription_pending',
            \Goldnead\Marketing\Events\MarketingSubscribed::class => 'marketing.subscription_confirmed',
            \Goldnead\Marketing\Events\MarketingUnsubscribed::class => 'marketing.unsubscribed',
        ];

        foreach ($subscription as $class => $eventType) {
            if (! class_exists($class)) {
                continue;
            }

            $registry->register($class, fn (object $event) => static::fromSubscription($event, $eventType), $eventType);
        }

        $campaign = [
            \Goldnead\Marketing\Events\CampaignSending::class => 'marketing.campaign_sending',
            \Goldnead\Marketing\Events\CampaignSent::class => 'marketing.campaign_sent',
        ];

        foreach ($campaign as $class => $eventType) {
            if (! class_exists($class)) {
                continue;
            }

            $registry->register($class, fn (object $event) => static::fromCampaign($event, $eventType), $eventType);
        }

        $message = [
            \Goldnead\Marketing\Events\MessageSent::class => ['marketing.email_sent', true],
            \Goldnead\Marketing\Events\MessageOpened::class => ['marketing.email_opened', false],
            \Goldnead\Marketing\Events\MessageClicked::class => ['marketing.email_clicked', false],
            \Goldnead\Marketing\Events\MessageBounced::class => ['marketing.email_bounced', true],
            \Goldnead\Marketing\Events\MessageComplained::class => ['marketing.email_complained', true],
        ];

        foreach ($message as $class => [$eventType, $deduped]) {
            if (! class_exists($class)) {
                continue;
            }

            $registry->register($class, fn (object $event) => static::fromMessage($event, $eventType, $deduped), $eventType);
        }
    }

    protected static function fromSubscription(object $event, string $eventType): array
    {
        $payload = $event->toPayload();

        return [
            'actor' => static::contactIdentity($payload),
            'subject' => $event->subscription,
            'dedupe_key' => static::dedupeKey($eventType, $payload['subscription_uuid'] ?? null),
            'properties' => [
                'list' => $payload['list'] ?? null,
                'status' => $payload['status'] ?? null,
                'source' => $payload['source'] ?? null,
            ],
        ];
    }

    protected static function fromCampaign(object $event, string $eventType): array
    {
        $payload = $event->toPayload();

        return [
            // A campaign transition is an operator action, not a contact's.
            'actor' => Identity::system(),
            'dedupe_key' => static::dedupeKey($eventType, $payload['campaign'] ?? null),
            'properties' => [
                'campaign' => $payload['campaign'] ?? null,
                'name' => $payload['name'] ?? null,
                'list' => $payload['list'] ?? null,
                'status' => $payload['status'] ?? null,
            ],
        ];
    }

    protected static function fromMessage(object $event, string $eventType, bool $deduped): array
    {
        $payload = $event->toPayload();

        return [
            'actor' => static::contactIdentity($payload),
            'subject' => $event->message,
            'dedupe_key' => $deduped ? static::dedupeKey($eventType, $payload['message_uuid'] ?? null) : null,
            'properties' => array_filter([
                'campaign' => $payload['campaign'] ?? null,
                'message_uuid' => $payload['message_uuid'] ?? null,
                'url' => $payload['url'] ?? null,
                'hard' => $payload['hard'] ?? null,
            ], fn ($value) => $value !== null),
        ];
    }

    /**
     * A dedupe key needs the thing it deduplicates on. Where that identifier is
     * absent there is no key — not an empty one.
     *
     * The distinction is not cosmetic. `$eventType.':'.($uuid ?? '')` produces a
     * value that is perfectly non-NULL, so `act_brand_dedupe_unique` binds it,
     * and every event of that type in the brand collapses onto the first row
     * ever written: the second subscription confirmation is silently returned as
     * a duplicate of the first, and the ledger loses facts it was built to keep.
     * Returning NULL is what makes the unique stand aside, which is exactly what
     * it should do for a row that carries nothing to be unique about — the row is
     * then held by `event_id` alone, like every other repeatable event here.
     *
     * These payloads merge `$event->metadata` last and can therefore override or
     * blank any of the identifiers, so the absence is reachable through the
     * marketing addon's public event API, not only through a future producer.
     */
    protected static function dedupeKey(string $eventType, mixed $identifier): ?string
    {
        $identifier = is_scalar($identifier) ? trim((string) $identifier) : '';

        return $identifier === '' ? null : $eventType.':'.$identifier;
    }

    /**
     * Marketing knows the contact uuid when the subscription is linked to a CRM
     * record; when it is not, the email still identifies the actor as a contact
     * without inventing an id for them.
     */
    protected static function contactIdentity(array $payload): Identity
    {
        $uuid = $payload['contact_uuid'] ?? null;
        $email = $payload['email'] ?? null;

        if ($uuid !== null) {
            return Identity::contact($uuid, $email, static::name($payload));
        }

        return new Identity(
            type: Identity::TYPE_CONTACT,
            email: $email,
            name: static::name($payload),
        );
    }

    protected static function name(array $payload): ?string
    {
        $name = trim(($payload['first_name'] ?? '').' '.($payload['last_name'] ?? ''));

        return $name === '' ? null : $name;
    }
}
