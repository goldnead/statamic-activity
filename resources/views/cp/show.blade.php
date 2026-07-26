@extends('statamic::layout')
@section('title', __('activity::cp.detail_title'))

@section('content')
    <header class="mb-6">
        <h1><code>{{ $activity->event_type }}</code></h1>
        <p class="text-gray-500 text-sm mt-1">
            {{ $activity->occurred_at?->format('Y-m-d H:i:s') }} · {{ $activity->source }} · <code>{{ $activity->event_id }}</code>
        </p>
    </header>

    <div class="card p-4 mb-4">
        <h2 class="text-sm font-medium mb-2">{{ __('activity::cp.detail_identity') }}</h2>
        <dl class="text-sm">
            <dt class="text-gray-500">actor</dt>
            <dd class="mb-2">{{ $activity->actor_type }} · {{ $activity->actor_id ?? '—' }}</dd>
            <dt class="text-gray-500">contact_uuid</dt>
            <dd class="mb-2">{{ $activity->contact_uuid ?? '—' }}</dd>
            <dt class="text-gray-500">user_id</dt>
            <dd class="mb-2">{{ $activity->user_id ?? '—' }}</dd>
            <dt class="text-gray-500">anonymous_id</dt>
            <dd>{{ $activity->anonymous_id ?? '—' }}</dd>
        </dl>
    </div>

    @if ($activity->subject_type)
        <div class="card p-4 mb-4">
            <h2 class="text-sm font-medium mb-2">{{ __('activity::cp.detail_subject') }}</h2>
            <p class="text-sm"><code>{{ $activity->subject_type }}</code> · {{ $activity->subject_id }}</p>
        </div>
    @endif

    <div class="card p-4 mb-4">
        <h2 class="text-sm font-medium mb-2">{{ __('activity::cp.detail_properties') }}</h2>
        <pre class="text-xs overflow-x-auto">{{ json_encode($activity->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>

    <div class="card p-4">
        <h2 class="text-sm font-medium mb-2">{{ __('activity::cp.detail_context') }}</h2>
        <pre class="text-xs overflow-x-auto">{{ json_encode($activity->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>
@endsection
