@extends('statamic::layout')
@section('title', __('activity::cp.title'))

@section('content')

    <header class="activity-inspector__header">
        <h1>{{ __('activity::cp.title') }}</h1>
        <p class="activity-inspector__intro">{{ __('activity::cp.intro') }}</p>
    </header>

    <div class="card activity-inspector__panel">
        <form method="GET" class="activity-inspector__filters">
            <div class="activity-inspector__field">
                <label class="activity-inspector__label" for="filter-event-type">{{ __('activity::cp.filter_event_type') }}</label>
                <input id="filter-event-type" type="text" name="event_type" class="activity-inspector__input" value="{{ $filters['event_type'] ?? '' }}">
            </div>
            <div class="activity-inspector__field">
                <label class="activity-inspector__label" for="filter-contact">{{ __('activity::cp.filter_contact') }}</label>
                <input id="filter-contact" type="text" name="contact_uuid" class="activity-inspector__input" value="{{ $filters['contact_uuid'] ?? '' }}">
            </div>
            <div class="activity-inspector__field">
                <label class="activity-inspector__label" for="filter-user">{{ __('activity::cp.filter_user') }}</label>
                <input id="filter-user" type="text" name="user_id" class="activity-inspector__input" value="{{ $filters['user_id'] ?? '' }}">
            </div>
            <div class="activity-inspector__field">
                <label class="activity-inspector__label" for="filter-from">{{ __('activity::cp.filter_from') }}</label>
                <input id="filter-from" type="date" name="from" class="activity-inspector__input" value="{{ $filters['from'] ?? '' }}">
            </div>
            <div class="activity-inspector__field">
                <label class="activity-inspector__label" for="filter-to">{{ __('activity::cp.filter_to') }}</label>
                <input id="filter-to" type="date" name="to" class="activity-inspector__input" value="{{ $filters['to'] ?? '' }}">
            </div>
            <div class="activity-inspector__field">
                <button type="submit" class="activity-inspector__button activity-inspector__button--primary">{{ __('activity::cp.filter_submit') }}</button>
            </div>
            <div class="activity-inspector__field">
                <a href="{{ cp_route('activity.index') }}" class="activity-inspector__button activity-inspector__button--plain">{{ __('activity::cp.filter_reset') }}</a>
            </div>
        </form>
    </div>

    @if ($activities->isEmpty())
        <div class="card activity-inspector__empty">{{ __('activity::cp.empty') }}</div>
    @else
        <div class="card activity-inspector__scroll">
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
                                {{ $activity->actor_type }}@if ($activity->actor_id)<span class="activity-inspector__muted"> · {{ $activity->actor_id }}</span>@endif
                                @if ($activity->anonymized)
                                    <span class="activity-inspector__badge">{{ __('activity::cp.anonymized') }}</span>
                                @endif
                            </td>
                            <td>{{ $activity->source }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="activity-inspector__pagination">{{ $activities->links() }}</div>
    @endif
@endsection

{{-- Deliberately in 'scripts', not 'content': Statamic 6 compiles the yielded
     Blade of a CP page into a Vue component template, and Vue's template
     compiler strips <style> tags. The 'scripts' yield sits outside the
     #statamic mount point, so the rules survive. --}}
@section('scripts')
    @include('activity::cp._styles')
@endsection
