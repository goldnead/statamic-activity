<?php

namespace Goldnead\Activity\Scopes\Filters;

/**
 * Anonymised facts keep their shape and lose their join keys. Being able to
 * separate the two is the only way an operator can check that a retention run
 * did what it claimed.
 */
class Anonymized extends ActivityFilter
{
    protected static $handle = 'activity_anonymized';

    public static function title()
    {
        return __('activity::cp.filter_anonymized');
    }

    public function fieldItems()
    {
        return [
            'anonymized' => [
                'display' => __('activity::cp.filter_anonymized'),
                'type' => 'select',
                'clearable' => true,
                'placeholder' => __('activity::cp.filter_any'),
                'options' => [
                    'yes' => __('activity::cp.filter_anonymized_only'),
                    'no' => __('activity::cp.filter_anonymized_excluded'),
                ],
            ],
        ];
    }

    public function apply($query, $values)
    {
        $value = $values['anonymized'] ?? null;

        if ($value === 'yes') {
            $query->where('anonymized', true);
        } elseif ($value === 'no') {
            $query->where('anonymized', false);
        }
    }

    public function badge($values)
    {
        $value = $values['anonymized'] ?? null;

        return __('activity::cp.filter_anonymized').': '.match ($value) {
            'yes' => __('activity::cp.filter_anonymized_only'),
            'no' => __('activity::cp.filter_anonymized_excluded'),
            default => '',
        };
    }
}
