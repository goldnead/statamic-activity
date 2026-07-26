<?php

namespace Goldnead\Activity\Contracts;

use Goldnead\Activity\Support\ActivityData;

/**
 * Last gate before a fact is persisted. Implementations may redact, truncate or
 * reshape — returning null drops the activity entirely, which is how an
 * application enforces "this must never be recorded" rules (§5.7: sensitive
 * domains do not mirror into a central event store).
 */
interface ActivitySanitizer
{
    public function sanitize(ActivityData $data): ?ActivityData;
}
