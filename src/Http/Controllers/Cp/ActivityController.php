<?php

namespace Goldnead\Activity\Http\Controllers\Cp;

use Goldnead\Activity\Models\Activity;
use Goldnead\Activity\Scopes\Filters\ActivityFilter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Statamic\CP\Column;
use Statamic\Facades\Scope;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;

/**
 * Read-only inspector. It lists and shows raw facts and nothing else: no counts,
 * no aggregates, no charts. Read models are a separate concern and a separate
 * addon — mixing them in here is how a ledger quietly turns into an analytics
 * product with no schema discipline.
 *
 * `index()` serves two representations of the same query: the HTML shell that
 * boots the native <ui-listing>, and the JSON that listing then fetches on
 * every search, sort, filter and page change. That is core's own arrangement
 * (see FormsController) and it is why there is no second "data" route.
 */
class ActivityController extends Controller
{
    use QueriesFilters;

    /**
     * Sorting is whitelisted rather than passed through: `sort` arrives from
     * the query string and goes into an ORDER BY, where an unbound column name
     * is not a bound parameter.
     */
    private const SORTABLE = ['occurred_at', 'event_type', 'source', 'actor_type'];

    private const MAX_PER_PAGE = 500;

    public function index(FilteredRequest $request)
    {
        Gate::authorize('view activity');

        if ($request->wantsJson()) {
            return $this->listing($request);
        }

        return view('activity::cp.index', [
            'columns' => collect($this->columns())->map->toArray()->all(),
            'filters' => Scope::filters(ActivityFilter::LISTING_KEY),
            'hasAny' => Activity::query()->exists(),
            'listingUrl' => cp_route('activity.index'),
            'deepLinkParameters' => $this->deepLinkParameters($request),
            'perPage' => $this->perPage(null),
        ]);
    }

    public function show(int $id)
    {
        Gate::authorize('view activity');

        // Brand scope still applies: an operator in brand A must not be able to
        // read a brand B row by guessing its id.
        $activity = Activity::query()->findOrFail($id);

        return view('activity::cp.show', [
            'activity' => $activity,
            'backUrl' => cp_route('activity.index'),
        ]);
    }

    /** The <ui-listing> response contract: `data` plus a `meta` carrying columns on every page. */
    private function listing(FilteredRequest $request): array
    {
        $query = Activity::query();

        $badges = $this->queryFilters(
            $query,
            $request->filters ?? [],
            ['handle' => ActivityFilter::LISTING_KEY],
        );

        $this->applyDeepLinkFilters($query, $request);
        $this->applySearch($query, (string) $request->input('search', ''));

        $sort = in_array($request->input('sort'), self::SORTABLE, true)
            ? $request->input('sort')
            : 'occurred_at';

        $order = $request->input('order') === 'asc' ? 'asc' : 'desc';

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query
            ->orderBy($sort, $order)
            ->orderBy('id', $order)
            ->paginate($this->perPage($request->input('perPage')))
            ->withQueryString();

        return [
            'data' => collect($paginator->items())->map(fn (Activity $activity) => $this->row($activity))->all(),
            'meta' => [
                'columns' => collect($this->columns())->map->toArray()->all(),
                'activeFilterBadges' => $badges,
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
        ];
    }

    private const DEEP_LINK_PARAMETERS = ['event_type', 'contact_uuid', 'user_id', 'source', 'anonymous_id', 'from', 'to'];

    /**
     * The pre-rewrite inspector filtered through plain query-string parameters.
     * Links people already hold keep working: the page hands them to the
     * listing as additional parameters, so they survive every subsequent
     * search, sort and page change. The filter panel is the way in for
     * everyone else.
     *
     * @return array<string, string>
     */
    private function deepLinkParameters(FilteredRequest $request): array
    {
        return collect(self::DEEP_LINK_PARAMETERS)
            ->filter(fn (string $key) => $request->filled($key))
            ->mapWithKeys(fn (string $key) => [$key => $request->string($key)->toString()])
            ->all();
    }

    private function applyDeepLinkFilters($query, FilteredRequest $request): void
    {
        foreach (['event_type', 'contact_uuid', 'user_id', 'source', 'anonymous_id'] as $column) {
            if ($request->filled($column)) {
                $query->where($column, $request->string($column)->toString());
            }
        }

        $query->occurredBetween(
            $this->parseBoundary($request->input('from')),
            $this->parseBoundary($request->input('to')),
        );
    }

    /**
     * A boundary that cannot be parsed is dropped instead of narrowing the
     * query: an operator must not be shown an empty ledger because of a typo
     * they cannot see.
     */
    private function parseBoundary(mixed $value): ?Carbon
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

    /**
     * Deliberately narrow: the identifying columns, matched as a prefix so the
     * index is still usable. The ledger is not a full-text store and pretending
     * otherwise on a table this size is how a CP page becomes a slow query.
     */
    private function applySearch($query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $term = str_replace(['%', '_'], ['\%', '\_'], $search).'%';

        $query->where(function ($query) use ($term, $search): void {
            $query
                ->where('event_type', 'like', $term)
                ->orWhere('source', 'like', $term)
                ->orWhere('actor_id', 'like', $term)
                ->orWhere('contact_uuid', 'like', $term)
                ->orWhere('anonymous_id', 'like', $term)
                ->orWhere('event_id', $search);
        });
    }

    private function perPage(mixed $requested): int
    {
        $default = (int) config('activity.cp.per_page', 50);
        $perPage = (int) ($requested ?: $default);

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }

    /** @return array<int, Column> */
    private function columns(): array
    {
        return [
            Column::make('occurred_at')->label(__('activity::cp.col_occurred')),
            Column::make('event_type')->label(__('activity::cp.col_event_type')),
            Column::make('actor')->label(__('activity::cp.col_actor'))->sortable(false),
            Column::make('source')->label(__('activity::cp.col_source')),
        ];
    }

    /** @return array<string, mixed> */
    private function row(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'occurred_at' => $activity->occurred_at?->format('Y-m-d H:i:s'),
            'event_type' => $activity->event_type,
            'actor' => $activity->actor_type,
            'actor_id' => $activity->actor_id,
            'source' => $activity->source,
            'anonymized' => (bool) $activity->anonymized,
            'show_url' => cp_route('activity.show', ['id' => $activity->id]),
        ];
    }
}
