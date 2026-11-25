<?php

namespace Goldnead\Activity;

use Closure;
use Goldnead\Activity\Contracts\ActivitySanitizer;
use Goldnead\Activity\Events\ActivityRecorded;
use Goldnead\Activity\Jobs\RecordActivityJob;
use Goldnead\Activity\Models\Activity;
use Goldnead\Activity\Producers\ProducerRegistry;
use Goldnead\Activity\Support\ActivityData;
use Goldnead\Activity\Support\ContextCapture;
use Goldnead\BrandContext\Facades\BrandContext;
use Goldnead\IdentityContracts\Facades\IdentityContext;
use Goldnead\IdentityContracts\Identity;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;

/**
 * The single write path into the ledger.
 *
 * Two rules shape everything here:
 *   1. Recording must be idempotent. The same fact arriving twice — webhook
 *      retry, replayed job, two producers observing one event — yields one row.
 *   2. Recording must never break the thing it observes. A failure to record is
 *      logged and swallowed, never propagated into a purchase or a signup.
 */
class ActivityRecorder
{
    public function __construct(
        protected ProducerRegistry $producers,
        protected ContextCapture $contextCapture,
    ) {}

    /**
     * Record a fact now. Returns the stored activity, or the pre-existing one
     * when this fact was already recorded. Returns null when recording is
     * disabled, the sanitizer dropped it, or persistence failed softly.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function record(string $eventType, array $attributes = []): ?Activity
    {
        return $this->write(ActivityData::make($eventType, $attributes));
    }

    /**
     * Queue the write. Preferred for hot paths: the actor and request context are
     * captured *now* (they no longer exist inside the worker), only the insert is
     * deferred.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function recordLater(string $eventType, array $attributes = []): void
    {
        if (! $this->enabled()) {
            return;
        }

        $data = $this->hydrate(ActivityData::make($eventType, $attributes));

        RecordActivityJob::dispatch($data->toArray())
            ->onConnection(config('activity.queue.connection'))
            ->onQueue(config('activity.queue.queue'));
    }

    /**
     * Write a pre-built payload. Used by the queued job and by producers.
     *
     * A failed insert is reported and answered with null, unless
     * $throwOnFailure is set (the queued job).
     */
    public function write(ActivityData $data, bool $hydrate = true, bool $throwOnFailure = false): ?Activity
    {
        if (! $this->enabled()) {
            return null;
        }

        $data = $hydrate ? $this->hydrate($data) : $data;

        // A payload that arrived pre-hydrated may still lack a brand (dispatched
        // by something other than recordLater). Never let that become a failed
        // insert — every row is brand-scoped, no exceptions.
        if ($data->brandId === null) {
            $data->brandId = BrandContext::hasCurrent() ? BrandContext::currentId() : BrandContext::defaultId();
        }

        $data = app(ActivitySanitizer::class)->sanitize($data);

        if ($data === null) {
            return null;
        }

        try {
            return $this->persist($data);
        } catch (QueryException $e) {
            // Recording is observation, not the business transaction. A ledger
            // problem must not roll back the purchase that produced the event.
            // The queued job has no transaction to protect and asks for the
            // exception, so the queue retries and failed_jobs keeps the event.
            if ($throwOnFailure) {
                throw $e;
            }

            report($e);

            return null;
        }
    }

    /**
     * Fill in everything that can only be known at the moment the event happens:
     * the brand, the actor, the request context.
     */
    public function hydrate(ActivityData $data): ActivityData
    {
        if ($data->brandId === null) {
            $data->brandId = BrandContext::hasCurrent()
                ? BrandContext::currentId()
                : BrandContext::defaultId();
        }

        if ($data->actor === null) {
            $data->actor = IdentityContext::current();
        } elseif (! $data->actor instanceof Identity) {
            $data->actor = IdentityContext::resolve($data->actor);
        }

        if ($data->source === null) {
            $data->source = config('activity.source');
        }

        $captured = $this->contextCapture->capture();

        if ($captured !== []) {
            // Explicitly passed context always wins over what we sniffed.
            $data = $data->withContext([...$captured, ...$data->context]);
        }

        return $data;
    }

    /**
     * Insert, tolerating the two idempotency keys. The read-then-write race is
     * handled by catching the unique violation and re-reading, which is the only
     * approach that holds under concurrent workers.
     */
    protected function persist(ActivityData $data): ?Activity
    {
        $attributes = $data->toAttributes();

        if ($existing = $this->findDuplicate($attributes)) {
            return $existing;
        }

        try {
            /** @var Activity $activity */
            $activity = Activity::create($attributes);
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateAfterViolation($attributes);
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // SQLSTATE 23000 also covers foreign key and NOT NULL failures. Without a
            // visible duplicate it was not a race on the unique index: rethrow.
            return $this->findDuplicate($attributes) ?? throw $e;
        }

        // Only a genuinely new row is an event. Both duplicate paths above
        // return early on purpose.
        ActivityRecorded::dispatch($activity);

        return $activity;
    }

    protected function findDuplicate(array $attributes): ?Activity
    {
        // Cross-brand by design: `event_id` is globally unique, so a lookup
        // scoped to the current brand would miss the row it collided with.
        return Activity::withoutGlobalScopes()
            ->where(function ($query) use ($attributes): void {
                $query->where('event_id', $attributes['event_id']);

                if (($attributes['dedupe_key'] ?? null) !== null) {
                    $query->orWhere(fn ($q) => $q
                        ->where('brand_id', $attributes['brand_id'])
                        ->where('dedupe_key', $attributes['dedupe_key']));
                }
            })
            ->first();
    }

    /**
     * Decided by SQLSTATE, not by the driver's message text. 23505 is PostgreSQL's
     * unique_violation; 23000 is the generic integrity class that MySQL and SQL
     * Server use for duplicates (and SQLite for every constraint).
     */
    protected function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true);
    }

    /**
     * The unique index rejected the row, but the competing row is not visible to
     * the lookup. Nothing is recorded, so the loss is logged.
     */
    protected function duplicateAfterViolation(array $attributes): ?Activity
    {
        $existing = $this->findDuplicate($attributes);

        if ($existing === null) {
            Log::warning('activity recorder: unique violation without a visible duplicate, activity not recorded', [
                'event_type' => $attributes['event_type'] ?? null,
            ]);
        }

        return $existing;
    }

    public function enabled(): bool
    {
        return (bool) config('activity.enabled', true);
    }

    /**
     * Map a domain event class onto the ledger.
     *
     * @param  Closure(object): (array<string, mixed>|null)  $mapper
     */
    public function registerProducer(string $eventClass, Closure $mapper, ?string $eventType = null): void
    {
        $this->producers->register($eventClass, $mapper, $eventType);
    }

    public function producers(): ProducerRegistry
    {
        return $this->producers;
    }

    public function query()
    {
        return Activity::query();
    }
}
