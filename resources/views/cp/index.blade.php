@extends('statamic::layout')
@section('title', __('activity::cp.title'))

@section('content')
    <header class="mb-6">
        <h1>{{ __('activity::cp.title') }}</h1>
        <p class="text-gray-500 text-sm mt-1">{{ __('activity::cp.intro') }}</p>
    </header>

    <div class="card p-4 mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <div>
                <label class="text-xs font-medium block mb-1">{{ __('activity::cp.filter_event_type') }}</label>
                <input type="text" name="event_type" class="input-text" value="{{ $filters['event_type'] ?? '' }}">
            </div>
            <div>
                <label class="text-xs font-medium block mb-1">{{ __('activity::cp.filter_contact') }}</label>
                <input type="text" name="contact_uuid" class="input-text" value="{{ $filters['contact_uuid'] ?? '' }}">
            </div>
            <div>
                <label class="text-xs font-medium block mb-1">{{ __('activity::cp.filter_user') }}</label>
                <input type="text" name="user_id" class="input-text" value="{{ $filters['user_id'] ?? '' }}">
            </div>
            <div>
                <label class="text-xs font-medium block mb-1">{{ __('activity::cp.filter_from') }}</label>
                <input type="date" name="from" class="input-text" value="{{ $filters['from'] ?? '' }}">
            </div>
            <div>
                <label class="text-xs font-medium block mb-1">{{ __('activity::cp.filter_to') }}</label>
                <input type="date" name="to" class="input-text" value="{{ $filters['to'] ?? '' }}">
            </div>
            <div class="flex gap-2">
                <button type="submit" class="btn-primary">{{ __('activity::cp.filter_submit') }}</button>
                <a href="{{ cp_route('activity.index') }}" class="btn">{{ __('activity::cp.filter_reset') }}</a>
            </div>
        </form>
    </div>

    @if ($activities->isEmpty())
        <div class="card p-6 text-center text-gray-500">{{ __('activity::cp.empty') }}</div>
    @else
        <div class="card p-0 overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>{{ __('activity::cp.col_occurred') }}</th>
                        <th>{{ __('activity::cp.col_event_type') }}</th>
                        <th>{{ __('activity::cp.col_actor') }}</th>
                        <th>{{ __('activity::cp.col_source') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($activities as $activity)
                        <tr>
                            <td>
                                <a href="{{ cp_route('activity.show', ['id' => $activity->id]) }}">
                                    {{ $activity->occurred_at?->format('Y-m-d H:i:s') }}
                                </a>
                            </td>
                            <td><code>{{ $activity->event_type }}</code></td>
                            <td>
                                {{ $activity->actor_type }}@if ($activity->actor_id)<span class="text-gray-500"> · {{ $activity->actor_id }}</span>@endif
                                @if ($activity->anonymized)
                                    <span class="badge-sm">{{ __('activity::cp.anonymized') }}</span>
                                @endif
                            </td>
                            <td>{{ $activity->source }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $activities->links() }}</div>
    @endif
@endsection
