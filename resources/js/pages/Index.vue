<script setup>
import { computed } from 'vue';
import { Head, Link } from '@statamic/cms/inertia';
import {
    Header, Description, Listing, Badge, Icon,
    EmptyStateMenu, EmptyStateItem, DocsCallout,
} from '@statamic/cms/ui';

const props = defineProps([
    'columns',              // Array<Column> — Statamic\CP\Column::toArray() shape
    'filters',              // Array — Scope::filters() output for the filter panel
    'hasAny',               // bool — decides empty state vs. listing
    'listingUrl',           // string — the same route, answering JSON
    'deepLinkParameters',   // Object<string,string> — pre-rewrite query-string links
    'perPage',              // int
    'docsUrl',              // string
    'recordingDocsUrl',     // string
]);

const isEmpty = computed(() => ! props.hasAny);
</script>

<template>
    <Head :title="__('activity::cp.title')" />

    <div class="max-w-page mx-auto" data-max-width-wrapper>
        <!--
            Core's empty state is a centred h1 rather than <Header>; see
            pages/forms/Index.vue. The heading is the one place the page builds
            its own element instead of using a component, because core does the
            same and <Header> would render the wrong thing here.
        -->
        <template v-if="isEmpty">
            <header class="py-8 pt-16 text-center">
                <h1 class="text-[25px] font-medium antialiased flex justify-center items-center gap-2 sm:gap-3">
                    <Icon name="pulse" class="size-5 text-gray-500" />{{ __('activity::cp.title') }}
                </h1>
            </header>

            <!-- No `description`: EmptyStateMenu declares the prop and renders it nowhere (v6.26.0). -->
            <EmptyStateMenu :heading="__('activity::cp.empty_heading')">
                <EmptyStateItem
                    icon="history"
                    :heading="__('activity::cp.empty_docs_heading')"
                    :description="__('activity::cp.empty_docs_description')"
                    :href="recordingDocsUrl"
                    target="_blank"
                />
            </EmptyStateMenu>
        </template>

        <template v-else>
            <!--
                <Header> declares only `icon` and `title`; its default slot is the
                right-hand action row (the h1 carries `md:flex-1`). A <Description>
                placed there is right-aligned across from the title, which no core
                screen does. It belongs under the header, the way statamic-notifications
                already renders it.
            -->
            <Header :title="__('activity::cp.title')" icon="pulse" />

            <Description :text="__('activity::cp.intro')" class="mb-4" />

            <!--
                Server mode: the listing re-requests this same route as JSON on
                every search, sort, filter and page change. No action-url is
                passed on purpose — the ledger is append-only, so there is
                nothing to do to a selected row and offering checkboxes would
                promise otherwise.
            -->
            <Listing
                :url="listingUrl"
                :columns="columns"
                :filters="filters"
                :additional-parameters="deepLinkParameters"
                :per-page="perPage"
                preferences-prefix="activity"
                sort-column="occurred_at"
                sort-direction="desc"
                push-query
            >
                <template #cell-occurred_at="{ row }">
                    <Link
                        :href="row.show_url"
                        :aria-label="row.event_type + ' — ' + row.occurred_at"
                        class="whitespace-nowrap"
                    >{{ row.occurred_at }}</Link>
                </template>

                <template #cell-event_type="{ row }">
                    <code class="text-xs">{{ row.event_type }}</code>
                </template>

                <template #cell-actor="{ row }">
                    <span class="flex items-center gap-2">
                        <span>{{ row.actor }}<span v-if="row.actor_id" class="text-gray-500"> · {{ row.actor_id }}</span></span>
                        <Badge
                            v-if="row.anonymized"
                            size="sm"
                            color="amber"
                            :text="__('activity::cp.anonymized')"
                            pill
                        />
                    </span>
                </template>
            </Listing>
        </template>

        <DocsCallout :topic="__('activity::cp.nav')" :url="docsUrl" />
    </div>
</template>
