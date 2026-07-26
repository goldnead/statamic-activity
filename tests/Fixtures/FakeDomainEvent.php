<?php

namespace Goldnead\Activity\Tests\Fixtures;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Stands in for a host application's domain event. Keeping the producer tests on
 * a fixture rather than a real marketing event means they exercise the registry
 * contract, not another package's internals.
 */
class FakeDomainEvent
{
    use Dispatchable;

    public function __construct(
        public string $contactUuid,
        public string $reference,
    ) {}
}
