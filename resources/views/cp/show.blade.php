@extends('statamic::layout')
@section('title', __('activity::cp.detail_title'))

@section('content')

    <header class="activity-inspector__header">
        <h1><code>{{ $activity->event_type }}</code></h1>
        <p class="activity-inspector__intro">
            {{ $activity->occurred_at?->format('Y-m-d H:i:s') }} · {{ $activity->source }} · <code>{{ $activity->event_id }}</code>
        </p>
    </header>

    <div class="card activity-inspector__panel">
        <h2 class="activity-inspector__label">{{ __('activity::cp.detail_identity') }}</h2>
        <dl class="activity-inspector__definitions">
            <dt>actor</dt>
            <dd>{{ $activity->actor_type }} · {{ $activity->actor_id ?? '—' }}</dd>
            <dt>contact_uuid</dt>
            <dd>{{ $activity->contact_uuid ?? '—' }}</dd>
            <dt>user_id</dt>
            <dd>{{ $activity->user_id ?? '—' }}</dd>
            <dt>anonymous_id</dt>
            <dd>{{ $activity->anonymous_id ?? '—' }}</dd>
        </dl>
    </div>

    @if ($activity->subject_type)
        <div class="card activity-inspector__panel">
            <h2 class="activity-inspector__label">{{ __('activity::cp.detail_subject') }}</h2>
            <p><code>{{ $activity->subject_type }}</code> · {{ $activity->subject_id }}</p>
        </div>
    @endif

    <div class="card activity-inspector__panel">
        <h2 class="activity-inspector__label">{{ __('activity::cp.detail_properties') }}</h2>
        <pre class="activity-inspector__pre">{{ json_encode($activity->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>

    <div class="card activity-inspector__panel">
        <h2 class="activity-inspector__label">{{ __('activity::cp.detail_context') }}</h2>
        <pre class="activity-inspector__pre">{{ json_encode($activity->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
@endsection

{{-- Deliberately in 'scripts', not 'content': Statamic 6 compiles the yielded
     Blade of a CP page into a Vue component template, and Vue's template
     compiler strips <style> tags. The 'scripts' yield sits outside the
     #statamic mount point, so the rules survive. --}}
@section('scripts')
    @include('activity::cp._styles')
@endsection
