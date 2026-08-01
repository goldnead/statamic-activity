<?php

namespace Goldnead\Activity\Scopes\Filters;

use Goldnead\Activity\Models\Activity;

/**
 * The source filter the README has always advertised. Until 1.0.6 the
 * controller implemented it and no screen offered it, so it was reachable only
 * by hand-editing the query string.
 */
class Source extends ActivityFilter
{
    protected static $handle = 'activity_source';

    protected $pinned = true;

    public static function title()
    {
        return __('activity::cp.filter_source');
    }

    public function fieldItems()
    {
        return [
            'source' => [
                'display' => __('activity::cp.filter_source'),
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
        if (! $value = $values['source'] ?? null) {
            return;
        }

        $query->where('source', $value);
    }

    public function badge($values)
    {
        return __('activity::cp.filter_source').': '.($values['source'] ?? '');
    }

    protected function options(): array
    {
        return Activity::query()
            ->select('source')
            ->whereNotNull('source')
            ->distinct()
            ->orderBy('source')
            ->limit(200)
            ->pluck('source', 'source')
            ->all();
    }
}
