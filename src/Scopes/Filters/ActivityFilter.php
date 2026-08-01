<?php

namespace Goldnead\Activity\Scopes\Filters;

use Statamic\Query\Scopes\Filter;

/**
 * Statamic registers scopes globally and `Filter::visibleTo()` defaults to
 * `true`, so a filter that does not answer that question turns up on the
 * Entries, Assets and Users listings as well. Everything in this folder is
 * scoped to the one listing it was written for.
 */
abstract class ActivityFilter extends Filter
{
    public const LISTING_KEY = 'activity';

    public function visibleTo($key)
    {
        return $key === self::LISTING_KEY;
    }
}
