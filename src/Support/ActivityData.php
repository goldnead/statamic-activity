<?php

namespace Goldnead\Activity\Support;

use Goldnead\IdentityContracts\Identity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The normalised shape of one activity before it is written. Producers and
 * callers build one of these; the sanitizer transforms it; the recorder persists
 * it. Keeping it separate from the model means a sanitizer can rewrite a record
 * without ever touching the database.
 */
final class ActivityData
{
    public function __construct(
        public string $eventType,
        public ?int $brandId = null,
        public ?string $eventId = null,
        public ?Identity $actor = null,
        public ?string $source = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public ?string $dedupeKey = null,
        public ?string $sessionId = null,
        public array $properties = [],
        public array $context = [],
        public ?Carbon $occurredAt = null,
        // Explicit join keys. A producer often knows the contact an event is
        // *about* while the actor is somebody else entirely — a CP user changing
        // a contact's status, an automation completing a task.
        public ?string $contactUuid = null,
        public ?string $userId = null,
        public ?string $anonymousId = null,
    ) {
        if (trim($this->eventType) === '') {
            throw new InvalidArgumentException('An activity needs an event type.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function make(string $eventType, array $attributes = []): self
    {
        $subject = $attributes['subject'] ?? null;

        return new self(
            eventType: $eventType,
            brandId: isset($attributes['brand_id']) ? (int) $attributes['brand_id'] : null,
            eventId: $attributes['event_id'] ?? null,
            actor: $attributes['actor'] ?? null,
            source: $attributes['source'] ?? null,
            subjectType: $attributes['subject_type'] ?? ($subject instanceof Model ? $subject::class : null),
            subjectId: isset($attributes['subject_id'])
                ? (string) $attributes['subject_id']
                : ($subject instanceof Model ? (string) $subject->getKey() : null),
            dedupeKey: $attributes['dedupe_key'] ?? null,
            sessionId: $attributes['session_id'] ?? null,
            properties: (array) ($attributes['properties'] ?? []),
            context: (array) ($attributes['context'] ?? []),
            occurredAt: isset($attributes['occurred_at']) ? Carbon::parse($attributes['occurred_at']) : null,
            contactUuid: isset($attributes['contact_uuid']) ? (string) $attributes['contact_uuid'] : null,
            userId: isset($attributes['user_id']) ? (string) $attributes['user_id'] : null,
            anonymousId: isset($attributes['anonymous_id']) ? (string) $attributes['anonymous_id'] : null,
        );
    }

    public function withActor(?Identity $actor): self
    {
        $clone = clone $this;
        $clone->actor = $actor;

        return $clone;
    }

    public function withProperties(array $properties): self
    {
        $clone = clone $this;
        $clone->properties = $properties;

        return $clone;
    }

    public function withContext(array $context): self
    {
        $clone = clone $this;
        $clone->context = $context;

        return $clone;
    }

    /**
     * Flattens into the column shape of the activities table. The actor is spread
     * across the dedicated join columns so queries never have to open a JSON blob.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $actor = $this->actor;
        $now = Carbon::now();

        return [
            'brand_id' => $this->brandId,
            'event_id' => $this->eventId ?? (string) Str::uuid(),
            'event_type' => $this->eventType,
            'actor_type' => $actor?->type,
            'actor_id' => $actor?->id,
            'contact_uuid' => $this->contactUuid ?? $actor?->contactUuid,
            'user_id' => $this->userId ?? $actor?->userId,
            'anonymous_id' => $this->anonymousId ?? $actor?->anonymousId,
            'session_id' => $this->sessionId,
            'source' => $this->source,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'dedupe_key' => $this->dedupeKey,
            'properties' => $this->properties ?: null,
            'context' => $this->context ?: null,
            'occurred_at' => $this->occurredAt ?? $now,
            'received_at' => $now,
        ];
    }

    /** Queue-safe representation — no models, no closures. */
    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType,
            'brand_id' => $this->brandId,
            'event_id' => $this->eventId,
            'actor' => $this->actor?->toArray(),
            'source' => $this->source,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'dedupe_key' => $this->dedupeKey,
            'session_id' => $this->sessionId,
            'properties' => $this->properties,
            'context' => $this->context,
            'occurred_at' => $this->occurredAt?->toIso8601String(),
            'contact_uuid' => $this->contactUuid,
            'user_id' => $this->userId,
            'anonymous_id' => $this->anonymousId,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            eventType: (string) $data['event_type'],
            brandId: isset($data['brand_id']) ? (int) $data['brand_id'] : null,
            eventId: $data['event_id'] ?? null,
            actor: isset($data['actor']) ? Identity::fromArray($data['actor']) : null,
            source: $data['source'] ?? null,
            subjectType: $data['subject_type'] ?? null,
            subjectId: $data['subject_id'] ?? null,
            dedupeKey: $data['dedupe_key'] ?? null,
            sessionId: $data['session_id'] ?? null,
            properties: (array) ($data['properties'] ?? []),
            context: (array) ($data['context'] ?? []),
            occurredAt: isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : null,
            contactUuid: $data['contact_uuid'] ?? null,
            userId: isset($data['user_id']) ? (string) $data['user_id'] : null,
            anonymousId: $data['anonymous_id'] ?? null,
        );
    }
}
