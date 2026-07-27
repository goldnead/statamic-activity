<?php

namespace Goldnead\Activity\Models;

use Goldnead\Activity\Exceptions\ImmutableActivity;
use Illuminate\Database\Eloquent\Builder;

/**
 * Extends the append-only guard to the query builder.
 *
 * Model events only fire for instance operations, so `$activity->save()` and
 * `$activity->delete()` were blocked while `Activity::query()->update([...])`
 * and `->delete()` went straight through — the guard covered the polite path and
 * missed the fast one. A ledger that can be rewritten in bulk is not a ledger.
 *
 * The retention and anonymisation commands legitimately need both, and they run
 * inside `Activity::mutable()`, which lifts the guard for the duration.
 */
class ImmutableBuilder extends Builder
{
    public function update(array $values)
    {
        if (! Activity::isMutable()) {
            throw ImmutableActivity::cannotUpdate();
        }

        return parent::update($values);
    }

    public function delete()
    {
        if (! Activity::isMutable()) {
            throw ImmutableActivity::cannotDelete();
        }

        return parent::delete();
    }

    public function forceDelete()
    {
        if (! Activity::isMutable()) {
            throw ImmutableActivity::cannotDelete();
        }

        return parent::forceDelete();
    }
}
