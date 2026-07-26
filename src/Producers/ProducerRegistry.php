<?php

namespace Goldnead\Activity\Producers;

use Closure;
use Illuminate\Support\Facades\Event;

/**
 * Maps domain events onto ledger writes.
 *
 * The registry is the documented extension point: an application teaches the
 * ledger about its own events without the ledger ever depending on them. A
 * mapper returns the attribute array for the activity, or null to skip this
 * particular occurrence.
 */
class ProducerRegistry
{
    /** @var array<string, array{mapper: Closure, event_type: string|null}> */
    protected array $producers = [];

    /**
     * Event classes already bound to the dispatcher. Tracked separately from the
     * mappers and deliberately never cleared: re-registering a producer (an app
     * overriding a bundled mapping) must replace the mapper, not add a second
     * listener that writes the fact twice.
     *
     * @var array<string, true>
     */
    protected array $listening = [];

    /**
     * @param  Closure(object): (array<string, mixed>|null)  $mapper
     */
    public function register(string $eventClass, Closure $mapper, ?string $eventType = null): static
    {
        $this->producers[$eventClass] = ['mapper' => $mapper, 'event_type' => $eventType];

        $this->listen($eventClass);

        return $this;
    }

    /** @param  array<string, array{0: string, 1: Closure}|Closure>  $map */
    public function registerMany(array $map): static
    {
        foreach ($map as $eventClass => $definition) {
            if ($definition instanceof Closure) {
                $this->register($eventClass, $definition);

                continue;
            }

            [$eventType, $mapper] = $definition;
            $this->register($eventClass, $mapper, $eventType);
        }

        return $this;
    }

    public function has(string $eventClass): bool
    {
        return isset($this->producers[$eventClass]);
    }

    /** @return array<int, string> */
    public function registered(): array
    {
        return array_keys($this->producers);
    }

    public function forget(): static
    {
        $this->producers = [];

        return $this;
    }

    /**
     * Resolve one dispatched event into activity attributes.
     *
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    public function resolve(object $event): ?array
    {
        $definition = $this->producers[$event::class] ?? null;

        if ($definition === null) {
            return null;
        }

        $attributes = ($definition['mapper'])($event);

        if ($attributes === null) {
            return null;
        }

        $eventType = $attributes['event_type'] ?? $definition['event_type'];

        if ($eventType === null) {
            return null;
        }

        unset($attributes['event_type']);

        return [$eventType, $attributes];
    }

    /**
     * One listener per registered class rather than a wildcard: a wildcard
     * listener would be invoked for every event in the application, which is a
     * meaningful cost on a busy request.
     */
    protected function listen(string $eventClass): void
    {
        if (isset($this->listening[$eventClass])) {
            return;
        }

        $this->listening[$eventClass] = true;

        Event::listen($eventClass, function (object $event): void {
            $resolved = $this->resolve($event);

            if ($resolved === null) {
                return;
            }

            [$eventType, $attributes] = $resolved;

            config('activity.queue.enabled', false)
                ? app('activity')->recordLater($eventType, $attributes)
                : app('activity')->record($eventType, $attributes);
        });
    }
}
