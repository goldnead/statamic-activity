{{--
    See the note at the top of index.blade.php for why this is Blade and what
    that implies. One rule is specific to this screen: everything below is a
    stored fact, i.e. attacker-influenced text, and the whole yielded block is
    compiled as a Vue template at runtime. A property containing {{ … }} would
    otherwise be evaluated as an expression, so every element that prints
    ledger content carries v-pre.
--}}
@extends('statamic::layout')
{{-- The layout yields this raw into an attribute, so it is escaped here. --}}
@section('title', e($activity->event_type).' · '.$activity->id)

@section('content')
    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <ui-header icon="pulse">
            <template #title>
                <code class="text-xl" v-pre>{{ $activity->event_type }}</code>
            </template>

            <ui-button
                href="{{ $backUrl }}"
                icon="arrow-left"
                text="{{ __('activity::cp.back_to_ledger') }}"
                variant="ghost"
            ></ui-button>
        </ui-header>

        <ui-panel heading="{{ __('activity::cp.detail_fact') }}">
            <ui-card>
                <dl class="grid grid-cols-1 sm:grid-cols-[12rem_1fr] gap-x-6 gap-y-2 text-sm">
                    <dt class="text-gray-500">{{ __('activity::cp.col_occurred') }}</dt>
                    <dd v-pre>{{ $activity->occurred_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>

                    <dt class="text-gray-500">{{ __('activity::cp.detail_received') }}</dt>
                    <dd v-pre>{{ $activity->received_at?->format('Y-m-d H:i:s') ?? '—' }}</dd>

                    <dt class="text-gray-500">{{ __('activity::cp.col_source') }}</dt>
                    <dd v-pre>{{ $activity->source ?? '—' }}</dd>

                    <dt class="text-gray-500">{{ __('activity::cp.detail_event_id') }}</dt>
                    <dd><code class="text-xs" v-pre>{{ $activity->event_id }}</code></dd>

                    <dt class="text-gray-500">{{ __('activity::cp.detail_dedupe_key') }}</dt>
                    <dd><code class="text-xs" v-pre>{{ $activity->dedupe_key ?? '—' }}</code></dd>
                </dl>

                @if ($activity->anonymized)
                    <div class="mt-4">
                        <ui-badge color="amber" text="{{ __('activity::cp.anonymized_notice') }}" pill></ui-badge>
                    </div>
                @endif
            </ui-card>
        </ui-panel>

        <ui-panel heading="{{ __('activity::cp.detail_identity') }}">
            <ui-card>
                <dl class="grid grid-cols-1 sm:grid-cols-[12rem_1fr] gap-x-6 gap-y-2 text-sm">
                    <dt class="text-gray-500">{{ __('activity::cp.col_actor') }}</dt>
                    <dd v-pre>{{ $activity->actor_type }}{{ $activity->actor_id ? ' · '.$activity->actor_id : '' }}</dd>

                    <dt class="text-gray-500">{{ __('activity::cp.filter_contact') }}</dt>
                    <dd v-pre>{{ $activity->contact_uuid ?? '—' }}</dd>

                    <dt class="text-gray-500">{{ __('activity::cp.filter_user') }}</dt>
                    <dd v-pre>{{ $activity->user_id ?? '—' }}</dd>

                    <dt class="text-gray-500">{{ __('activity::cp.filter_anonymous') }}</dt>
                    <dd v-pre>{{ $activity->anonymous_id ?? '—' }}</dd>
                </dl>
            </ui-card>
        </ui-panel>

        @if ($activity->subject_type)
            <ui-panel heading="{{ __('activity::cp.detail_subject') }}">
                <ui-card>
                    <p class="text-sm">
                        <code class="text-xs" v-pre>{{ $activity->subject_type }}</code>
                        <span class="text-gray-500"> · </span>
                        <span v-pre>{{ $activity->subject_id }}</span>
                    </p>
                </ui-card>
            </ui-panel>
        @endif

        <ui-panel heading="{{ __('activity::cp.detail_properties') }}">
            <ui-card>
                <pre class="overflow-x-auto text-xs" v-pre>{{ json_encode($activity->properties, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </ui-card>
        </ui-panel>

        <ui-panel heading="{{ __('activity::cp.detail_context') }}">
            <ui-card>
                <pre class="overflow-x-auto text-xs" v-pre>{{ json_encode($activity->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
            </ui-card>
        </ui-panel>
    </div>
@endsection
