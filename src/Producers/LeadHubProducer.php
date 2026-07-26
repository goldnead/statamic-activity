<?php

namespace Goldnead\Activity\Producers;

use Goldnead\IdentityContracts\Identity;

/**
 * Bundled producer for `goldnead/statamic-leadhub`. Every LeadHub event shares
 * one shape (`contact`, `actor`, `metadata`), so a single mapper covers all of
 * them and the map below is purely a naming table.
 *
 * This does not replace `leadhub_events`: that table is the CRM's own contact
 * timeline and stays untouched. The ledger records the same facts for a
 * different purpose — cross-domain analysis — and the two are allowed to
 * overlap.
 */
class LeadHubProducer
{
    /** @return array<class-string, string> */
    public static function map(): array
    {
        return [
            \Goldnead\Leadhub\Events\LeadHubContactCreated::class => 'crm.contact_created',
            \Goldnead\Leadhub\Events\LeadHubContactUpdated::class => 'crm.contact_updated',
            \Goldnead\Leadhub\Events\LeadHubContactArchived::class => 'crm.contact_archived',
            \Goldnead\Leadhub\Events\LeadHubContactsMerged::class => 'crm.contacts_merged',
            \Goldnead\Leadhub\Events\LeadHubStatusChanged::class => 'crm.status_changed',
            \Goldnead\Leadhub\Events\LeadHubContactScoreChanged::class => 'crm.score_changed',
            \Goldnead\Leadhub\Events\LeadHubContactEnteredSegment::class => 'crm.segment_entered',
            \Goldnead\Leadhub\Events\LeadHubContactLeftSegment::class => 'crm.segment_left',
            \Goldnead\Leadhub\Events\LeadHubTagAdded::class => 'crm.tag_added',
            \Goldnead\Leadhub\Events\LeadHubTagRemoved::class => 'crm.tag_removed',
            \Goldnead\Leadhub\Events\LeadHubOpportunityCreated::class => 'crm.opportunity_created',
            \Goldnead\Leadhub\Events\LeadHubOpportunityStageChanged::class => 'crm.opportunity_stage_changed',
            \Goldnead\Leadhub\Events\LeadHubOpportunityWon::class => 'crm.opportunity_won',
            \Goldnead\Leadhub\Events\LeadHubOpportunityLost::class => 'crm.opportunity_lost',
            \Goldnead\Leadhub\Events\LeadHubTaskCreated::class => 'crm.task_created',
            \Goldnead\Leadhub\Events\LeadHubTaskCompleted::class => 'crm.task_completed',
            \Goldnead\Leadhub\Events\LeadHubEmailLinkClicked::class => 'crm.email_link_clicked',
            \Goldnead\Leadhub\Events\LeadHubSourceIngested::class => 'crm.source_ingested',
        ];
    }

    public static function register(ProducerRegistry $registry): void
    {
        foreach (static::map() as $class => $eventType) {
            if (! class_exists($class)) {
                continue;
            }

            $registry->register($class, fn (object $event) => static::fromEvent($event, $eventType), $eventType);
        }
    }

    protected static function fromEvent(object $event, string $eventType): ?array
    {
        $contact = $event->contact ?? null;

        if ($contact === null) {
            return null;
        }

        $uuid = $contact->uuid ?? null;

        return [
            // The contact is the subject of the record; whoever triggered it (a CP
            // user, an automation) is the actor.
            'actor' => static::actor($event),
            'contact_uuid' => $uuid,
            'subject' => $contact,
            'properties' => array_filter([
                'contact_uuid' => $uuid,
                'status' => $contact->status ?? null,
            ] + (array) ($event->metadata ?? []), fn ($value) => $value !== null),
            'dedupe_key' => static::dedupeKey($event, $eventType, $uuid),
        ];
    }

    protected static function actor(object $event): Identity
    {
        $actor = $event->actor ?? null;

        if (! is_array($actor) || $actor === []) {
            return Identity::system();
        }

        $id = $actor['id'] ?? $actor['user_id'] ?? null;

        return $id === null
            ? Identity::system()
            : Identity::user($id, $actor['email'] ?? null, $actor['name'] ?? null);
    }

    /**
     * LeadHub's own events carry a dedupe key in their metadata when the
     * underlying fact is a transition; anything else stays repeatable and relies
     * on `event_id` for idempotency.
     */
    protected static function dedupeKey(object $event, string $eventType, ?string $uuid): ?string
    {
        $metadata = (array) ($event->metadata ?? []);

        if (isset($metadata['dedupe_key'])) {
            return $eventType.':'.$metadata['dedupe_key'];
        }

        return null;
    }
}
