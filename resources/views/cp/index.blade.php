{{--
    Statamic 6 has no Blade CP pages of its own; this addon ships no Node
    toolchain, so it renders through the NonInertiaPage compatibility shim
    (statamic.js: the yielded content becomes a Vue template inside the core
    Layout). Everything below is therefore compiled by Vue at runtime, which is
    what makes the globally registered <ui-*> components resolve.

    Two consequences that are easy to trip over here:
      - Void syntax does not exist for custom elements. `<ui-button />` is read
        by the HTML parser as an *open* tag and swallows the rest of the page.
        Every component below is closed explicitly.
      - Attribute names are lowercased by the HTML parser, so props are written
        in kebab-case (`:action-url`, never `:actionUrl`).
--}}
@extends('statamic::layout')
@section('title', __('activity::cp.title'))

@section('content')
    <div class="max-w-page mx-auto" data-max-width-wrapper>
        @if (! $hasAny)
            {{-- Core's empty state is a centred h1 rather than <ui-header>; see pages/forms/Index.vue. --}}
            <header class="py-8 pt-16 text-center">
                <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                    <ui-icon name="pulse" class="size-5 text-gray-500"></ui-icon>{{ __('activity::cp.title') }}
                </h1>
            </header>

            {{-- No `description`: EmptyStateMenu declares the prop and renders it nowhere (v6.26.0). --}}
            <ui-empty-state-menu heading="{{ __('activity::cp.empty_heading') }}">
                <ui-empty-state-item
                    icon="history"
                    heading="{{ __('activity::cp.empty_docs_heading') }}"
                    description="{{ __('activity::cp.empty_docs_description') }}"
                    href="https://github.com/goldnead/statamic-activity#recording"
                    target="_blank"
                ></ui-empty-state-item>
            </ui-empty-state-menu>
        @else
            <ui-header title="{{ __('activity::cp.title') }}" icon="pulse">
                <ui-description text="{{ __('activity::cp.intro') }}"></ui-description>
            </ui-header>

            {{--
                Server mode: the listing re-requests this same route as JSON on
                every search, sort, filter and page change. No action-url is
                passed on purpose — the ledger is append-only, so there is
                nothing to do to a selected row and offering checkboxes would
                promise otherwise.
            --}}
            <ui-listing
                url="{{ $listingUrl }}"
                :columns="{{ json_encode($columns) }}"
                :filters="{{ json_encode($filters) }}"
                :additional-parameters="{{ json_encode($deepLinkParameters) }}"
                :per-page="{{ $perPage }}"
                preferences-prefix="activity"
                sort-column="occurred_at"
                sort-direction="desc"
                push-query
            >
                <template #cell-occurred_at="{ row }">
                    <a :href="row.show_url" :aria-label="row.event_type + ' — ' + row.occurred_at" class="whitespace-nowrap">@{{ row.occurred_at }}</a>
                </template>

                <template #cell-event_type="{ row }">
                    <code class="text-xs">@{{ row.event_type }}</code>
                </template>

                <template #cell-actor="{ row }">
                    <span class="flex items-center gap-2">
                        <span>@{{ row.actor }}<span v-if="row.actor_id" class="text-gray-500"> · @{{ row.actor_id }}</span></span>
                        <ui-badge v-if="row.anonymized" size="sm" color="amber" text="{{ __('activity::cp.anonymized') }}" pill></ui-badge>
                    </span>
                </template>
            </ui-listing>
        @endif

        <ui-docs-callout
            topic="{{ __('activity::cp.nav') }}"
            url="https://github.com/goldnead/statamic-activity#control-panel"
        ></ui-docs-callout>
    </div>
@endsection
