<?php

namespace Goldnead\Activity\Exceptions;

use RuntimeException;

class ImmutableActivity extends RuntimeException
{
    public static function cannotUpdate(): self
    {
        return new self('Activities are append-only and cannot be updated. Record a correcting activity instead, or use the anonymisation command.');
    }

    public static function cannotDelete(): self
    {
        return new self('Activities are append-only and cannot be deleted. Use the retention command (activity:prune) instead.');
    }
}
