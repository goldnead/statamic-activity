<?php

namespace Goldnead\Activity\Scopes\Filters;

use Goldnead\Activity\Models\Activity;

/**
 * Event type is the primary axis an operator navigates the ledger by, so it is
 * pinned open in the filter panel rather than hidden behind the "add filter"
 * dropdown.
 */
class EventType extends ActivityFilter
{
    protected static $handle = 'activity_event_type';

    protected $pinned = true;

    public static function title()
    {
        return __('activity::cp.filter_event_type');
    }

    public function fieldItems()
    {
        return [
            'event_type' => [
                'display' => __('activity::cp.filter_event_type'),
                'type' => 'select',
                'clearable' => true,
                'searchable' => true,
                'taggable' => true,
                'placeholder' => __('activity::cp.filter_any'),
                'options' => $this->options(),
            ],
        ];
    }

    public function apply($query, $values)
    {
        if (! $value = $values['event_type'] ?? null) {
            return;
        }

        $query->where('event_type', $value);
    }

    public function badge($values)
    {
        return __('activity::cp.filter_event_type').': '.($values['event_type'] ?? '');
    }

    /**
     * Distinct values, capped: a ledger is unbounded and this runs on every
     * render of the listing page. `taggable` covers whatever falls off the end.
     */
    protected function options(): array
    {
        return Activity::query()
            ->select('event_type')
            ->distinct()
            ->orderBy('event_type')
            ->limit(200)
            ->pluck('event_type', 'event_type')
            ->all();
    }
}
