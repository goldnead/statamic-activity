import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Index from '../../resources/js/pages/Index.vue';
import { visibleText } from './helpers.js';

/**
 * The ledger index. Its whole job is to decide between the empty state and the
 * native <Listing>, and to hand that listing the parameters the server worked
 * out. Both of those used to be Blade `@if`s and JSON-in-an-attribute; the port
 * moved them into props, so this is where they are now checked.
 */

const columns = [
    { field: 'occurred_at', label: 'Occurred' },
    { field: 'event_type', label: 'Event type' },
    { field: 'actor', label: 'Actor' },
    { field: 'source', label: 'Source' },
];

function page(overrides = {}) {
    return mount(Index, {
        props: {
            columns,
            filters: [{ handle: 'event_type', title: 'Event type' }],
            hasAny: true,
            listingUrl: '/cp/activity',
            deepLinkParameters: {},
            perPage: 50,
            docsUrl: 'https://example.test/#control-panel',
            recordingDocsUrl: 'https://example.test/#recording',
            ...overrides,
        },
    });
}

describe('the ledger index', () => {
    it('boots the native listing rather than a hand-built table', () => {
        const wrapper = page();

        expect(wrapper.findComponent({ name: 'Listing' }).exists()).toBe(true);
        expect(wrapper.find('table').exists()).toBe(false);
    });

    it('puts the ledger in server mode against the route it came from', () => {
        const listing = page().findComponent({ name: 'Listing' });

        // `url` is what makes the listing re-request on every search, sort and
        // page change. Handing it `items` instead would silently drop
        // pagination and server-side filtering.
        expect(listing.attributes('data-attr-url')).toBe('/cp/activity');
        expect(listing.attributes('data-attr-items')).toBeUndefined();
    });

    it('offers no bulk actions, because the ledger is append-only', () => {
        // No action-url means <Listing> renders no checkboxes and no bulk
        // toolbar (Listing.vue: hasActions = !!props.actionUrl). Offering them
        // on an immutable ledger would promise something that cannot happen.
        expect(page().findComponent({ name: 'Listing' }).attributes('data-attr-action-url'))
            .toBeUndefined();
    });

    it('keeps saved views and column preferences working', () => {
        // No preferences-prefix means no presets and no persisted columns.
        expect(page().findComponent({ name: 'Listing' }).attributes('data-attr-preferences-prefix'))
            .toBe('activity');
    });

    it('sorts newest first out of the box', () => {
        const listing = page().findComponent({ name: 'Listing' });

        expect(listing.attributes('data-attr-sort-column')).toBe('occurred_at');
        expect(listing.attributes('data-attr-sort-direction')).toBe('desc');
    });

    it('shows the empty state instead of the listing before anything is recorded', () => {
        const wrapper = page({ hasAny: false });

        expect(wrapper.findComponent({ name: 'EmptyStateMenu' }).exists()).toBe(true);
        expect(wrapper.findComponent({ name: 'Listing' }).exists()).toBe(false);
        expect(wrapper.findComponent({ name: 'Header' }).exists()).toBe(false);

        // Core's empty state is a centred h1 rather than <Header>; see
        // pages/forms/Index.vue.
        expect(wrapper.find('header h1').exists()).toBe(true);
    });

    it('points the empty state at the recording docs the server named', () => {
        const item = page({ hasAny: false }).findComponent({ name: 'EmptyStateItem' });

        expect(item.attributes('data-attr-href')).toBe('https://example.test/#recording');
    });

    it('links the docs callout at the URL the server named', () => {
        expect(page().findComponent({ name: 'DocsCallout' }).attributes('data-attr-url'))
            .toBe('https://example.test/#control-panel');
    });

    it('holds no URLs of its own', () => {
        // Every link on this page arrives as a prop. A hard-coded github URL in
        // the bundle is a URL nobody can change without a release.
        expect(visibleText(page())).not.toContain('github.com');
    });
});
