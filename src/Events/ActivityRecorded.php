<?php

namespace Goldnead\Activity\Events;

use Goldnead\Activity\Models\Activity;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired once per fact that was actually written. A deduplicated write returns
 * the row that already existed and fires nothing: to a downstream consumer that
 * is not a new event, and treating it as one is how a read model double-counts.
 *
 * This is the hook the ledger's whole premise depends on. Read models belong in
 * other addons, and without an event they would have to poll the table.
 */
class ActivityRecorded
{
    use Dispatchable;

    public function __construct(public readonly Activity $activity) {}
}
