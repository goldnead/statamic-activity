<?php

namespace Goldnead\Activity\Scopes\Filters;

/**
 * The three join keys a fact can carry. They are separate inputs rather than
 * one "who" box because they are different identifiers with different
 * lifetimes, and matching the wrong one silently returns the wrong history.
 */
class Identity extends ActivityFilter
{
    protected static $handle = 'activity_identity';

    public static function title()
    {
        return __('activity::cp.filter_identity');
    }

    public function fieldItems()
    {
        return [
            'contact_uuid' => [
                'display' => __('activity::cp.filter_contact'),
                'type' => 'text',
                'full_width' => true,
            ],
            'user_id' => [
                'display' => __('activity::cp.filter_user'),
                'type' => 'text',
                'full_width' => true,
            ],
            'anonymous_id' => [
                'display' => __('activity::cp.filter_anonymous'),
                'type' => 'text',
                'full_width' => true,
            ],
        ];
    }

    public function apply($query, $values)
    {
        foreach (['contact_uuid', 'user_id', 'anonymous_id'] as $column) {
            if (filled($values[$column] ?? null)) {
                $query->where($column, $values[$column]);
            }
        }
    }

    public function badge($values)
    {
        return collect(['contact_uuid', 'user_id', 'anonymous_id'])
            ->filter(fn ($column) => filled($values[$column] ?? null))
            ->map(fn ($column) => $column.': '.$values[$column])
            ->implode(', ');
    }
}
