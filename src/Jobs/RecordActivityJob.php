<?php

namespace Goldnead\Activity\Jobs;

use Goldnead\Activity\Support\ActivityData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Deferred ledger write. Carries a plain array rather than models, so a queued
 * activity still describes the world as it was when the event happened, even if
 * the underlying records change before the worker picks it up.
 *
 * Uniqueness is belt-and-braces: the recorder is idempotent on its own, but
 * ShouldBeUnique stops a retry storm from queuing thousands of identical writes.
 */
class RecordActivityJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public array $payload) {}

    public function uniqueId(): string
    {
        return (string) ($this->payload['event_id']
            ?? $this->payload['dedupe_key']
            ?? md5(json_encode($this->payload)));
    }

    public function uniqueFor(): int
    {
        return (int) config('activity.queue.unique_for', 3600);
    }

    public function handle(): void
    {
        // Already hydrated at dispatch time — the request context and actor are
        // long gone by now, so re-hydrating here would silently rewrite them.
        app('activity')->write(ActivityData::fromArray($this->payload), hydrate: false);
    }
}
