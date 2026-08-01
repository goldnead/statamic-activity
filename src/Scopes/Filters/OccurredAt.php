<?php

namespace Goldnead\Activity\Scopes\Filters;

use Illuminate\Support\Carbon;

/**
 * Replaces the two hand-rolled `<input type="date">` boxes, whose values went
 * unvalidated into a raw comparison: a malformed date produced an empty result
 * set that was indistinguishable from "no matches". A native date field cannot
 * emit one, and anything that still slips through is rejected here rather than
 * silently narrowing the query.
 */
class OccurredAt extends ActivityFilter
{
    protected static $handle = 'activity_occurred_at';

    protected $pinned = true;

    public static function title()
    {
        return __('activity::cp.filter_occurred');
    }

    public function fieldItems()
    {
        return [
            'operator' => [
                'display' => __('activity::cp.filter_comparison'),
                'type' => 'select',
                'clearable' => false,
                'default' => 'between',
                'options' => [
                    '>=' => __('activity::cp.filter_from'),
                    '<=' => __('activity::cp.filter_to'),
                    'between' => __('activity::cp.filter_between'),
                ],
            ],
            'value' => [
                'display' => __('activity::cp.filter_date'),
                'type' => 'date',
                'full_width' => true,
                'clearable' => false,
                'if' => ['operator' => 'contains_any >=, <='],
            ],
            'range_value' => [
                'display' => __('activity::cp.filter_range'),
                'type' => 'date',
                'mode' => 'range',
                'full_width' => true,
                'clearable' => false,
                'if' => ['operator' => 'between'],
            ],
        ];
    }

    public function apply($query, $values)
    {
        $operator = $values['operator'] ?? 'between';

        if ($operator === 'between') {
            $start = $this->parse($values['range_value']['start'] ?? null);
            $end = $this->parse($values['range_value']['end'] ?? null);

            if ($start) {
                $query->where('occurred_at', '>=', $start->startOfDay());
            }

            if ($end) {
                $query->where('occurred_at', '<=', $end->endOfDay());
            }

            return;
        }

        if (! $value = $this->parse($values['value'] ?? null)) {
            return;
        }

        $query->where('occurred_at', $operator, $operator === '<=' ? $value->endOfDay() : $value->startOfDay());
    }

    public function badge($values)
    {
        $operator = $values['operator'] ?? 'between';

        if ($operator === 'between') {
            $start = $this->parse($values['range_value']['start'] ?? null)?->toDateString();
            $end = $this->parse($values['range_value']['end'] ?? null)?->toDateString();

            return __('activity::cp.filter_occurred').': '.$start.' – '.$end;
        }

        $label = $operator === '<=' ? __('activity::cp.filter_to') : __('activity::cp.filter_from');

        return $label.': '.$this->parse($values['value'] ?? null)?->toDateString();
    }

    /** An unparseable date is dropped rather than turned into "now". */
    protected function parse(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
