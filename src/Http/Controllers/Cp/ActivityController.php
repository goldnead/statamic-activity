<?php

namespace Goldnead\Activity\Http\Controllers\Cp;

use Goldnead\Activity\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Read-only inspector. It lists and shows raw facts and nothing else: no counts,
 * no aggregates, no charts. Read models are a separate concern and a separate
 * addon — mixing them in here is how a ledger quietly turns into an analytics
 * product with no schema discipline.
 */
class ActivityController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('view activity');

        $activities = Activity::query()
            ->when($request->filled('event_type'), fn ($q) => $q->where('event_type', $request->string('event_type')->toString()))
            ->when($request->filled('contact_uuid'), fn ($q) => $q->where('contact_uuid', $request->string('contact_uuid')->toString()))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->string('user_id')->toString()))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')->toString()))
            ->occurredBetween($request->input('from'), $request->input('to'))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate((int) config('activity.cp.per_page', 50))
            ->withQueryString();

        return view('activity::cp.index', [
            'activities' => $activities,
            'filters' => $request->only(['event_type', 'contact_uuid', 'user_id', 'source', 'from', 'to']),
        ]);
    }

    public function show(int $id)
    {
        Gate::authorize('view activity');

        // Brand scope still applies: an operator in brand A must not be able to
        // read a brand B row by guessing its id.
        $activity = Activity::query()->findOrFail($id);

        return view('activity::cp.show', ['activity' => $activity]);
    }
}
