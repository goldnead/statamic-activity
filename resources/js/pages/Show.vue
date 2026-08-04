<script setup>
import { computed } from 'vue';
import { Head } from '@statamic/cms/inertia';
import { Header, Button, Panel, Card, Badge } from '@statamic/cms/ui';

const props = defineProps([
    'fact',     // the single ledger row, already reduced server-side to the fields this screen shows
    'backUrl',  // string
]);

/**
 * Nothing on this screen is optional in the layout sense: a missing value still
 * occupies its row, so the reader can tell "not recorded" from "not shown".
 */
const dash = (value) => (value === null || value === undefined || value === '' ? '—' : String(value));

const actor = computed(() => props.fact.actor_type + (props.fact.actor_id ? ' · ' + props.fact.actor_id : ''));

/**
 * PHP's JSON_PRETTY_PRINT indents with four spaces and neither side escapes
 * slashes or unicode here, so this renders the same text the Blade screen did.
 */
const pretty = (value) => JSON.stringify(value ?? null, null, 4);
</script>

<template>
    <Head :title="fact.event_type + ' · ' + fact.id" />

    <!--
        The narrow detail width from ui-vocabulary §2.3 — the same pair core uses
        on pages/forms/Show.vue and pages/preferences/Edit.vue. `data-max-width-wrapper`
        is what opts it into the header's expand-layout toggle.
    -->
    <div class="max-w-5xl 3xl:max-w-6xl mx-auto" data-max-width-wrapper>
        <Header icon="pulse">
            <template #title>
                <code class="text-xl">{{ fact.event_type }}</code>
            </template>

            <Button
                :href="backUrl"
                icon="arrow-left"
                :text="__('activity::cp.back_to_ledger')"
                variant="ghost"
            />
        </Header>

        <Panel :heading="__('activity::cp.detail_fact')">
            <Card>
                <dl class="grid grid-cols-1 sm:grid-cols-[12rem_1fr] gap-x-6 gap-y-2 text-sm">
                    <dt class="text-gray-500">{{ __('activity::cp.col_occurred') }}</dt>
                    <dd>{{ dash(fact.occurred_at) }}</dd>

                    <dt class="text-gray-500">{{ __('activity::cp.detail_received') }}</dt>
                    <dd>{{ dash(fact.received_at) }}</dd>

                    <dt class="text-gray-500">{{ __('activity::cp.col_source') }}</dt>
                    <dd>{{ dash(fact.source) }}</dd>

                    <dt class="text-gray-500">{{ __('activity::cp.detail_event_id') }}</dt>
                    <dd><code class="text-xs">{{ fact.event_id }}</code></dd>

                    <dt class="text-gray-500">{{ __('activity::cp.detail_dedupe_key') }}</dt>
                    <dd><code class="text-xs">{{ dash(fact.dedupe_key) }}</code></dd>
                </dl>

                <div v-if="fact.anonymized" class="mt-4">
                    <Badge color="amber" :text="__('activity::cp.anonymized_notice')" pill />
                </div>
            </Card>
        </Panel>

        <Panel :heading="__('activity::cp.detail_identity')">
            <Card>
                <dl class="grid grid-cols-1 sm:grid-cols-[12rem_1fr] gap-x-6 gap-y-2 text-sm">
                    <dt class="text-gray-500">{{ __('activity::cp.col_actor') }}</dt>
                    <dd>{{ actor }}</dd>

                    <dt class="text-gray-500">{{ __('activity::cp.filter_contact') }}</dt>
                    <dd>{{ dash(fact.contact_uuid) }}</dd>

                    <dt class="text-gray-500">{{ __('activity::cp.filter_user') }}</dt>
                    <dd>{{ dash(fact.user_id) }}</dd>

                    <dt class="text-gray-500">{{ __('activity::cp.filter_anonymous') }}</dt>
                    <dd>{{ dash(fact.anonymous_id) }}</dd>
                </dl>
            </Card>
        </Panel>

        <Panel v-if="fact.subject_type" :heading="__('activity::cp.detail_subject')">
            <Card>
                <p class="text-sm">
                    <code class="text-xs">{{ fact.subject_type }}</code>
                    <span class="text-gray-500"> · </span>
                    <span>{{ fact.subject_id }}</span>
                </p>
            </Card>
        </Panel>

        <Panel :heading="__('activity::cp.detail_properties')">
            <Card>
                <pre class="overflow-x-auto text-xs">{{ pretty(fact.properties) }}</pre>
            </Card>
        </Panel>

        <Panel :heading="__('activity::cp.detail_context')">
            <Card>
                <pre class="overflow-x-auto text-xs">{{ pretty(fact.context) }}</pre>
            </Card>
        </Panel>
    </div>
</template>
