import { describe, it, expect } from 'vitest';
import { mount } from '@vue/test-utils';
import Show from '../../resources/js/pages/Show.vue';
import { findByAttr, visibleText } from './helpers.js';

/**
 * A single fact. Everything on this screen is stored, attacker-influenced text.
 * On the Blade shell that was a live hazard — the yielded block was compiled as
 * a Vue template in the operator's browser, so every element printing ledger
 * content needed v-pre. A compiled SFC interpolates instead of compiling, which
 * is what the first test below pins.
 */

const fact = {
    id: 42,
    event_type: 'commerce.purchase_completed',
    event_id: 'evt_01HZ',
    dedupe_key: 'dk_01HZ',
    occurred_at: '2026-08-01 09:15:00',
    received_at: '2026-08-01 09:15:02',
    source: 'checkout',
    anonymized: false,
    actor_type: 'user',
    actor_id: '7',
    contact_uuid: 'c-1',
    user_id: '7',
    anonymous_id: null,
    subject_type: null,
    subject_id: null,
    properties: { product: 'stimmnotfallplan' },
    context: { ip: '127.0.0.1' },
};

function page(overrides = {}) {
    return mount(Show, {
        props: { fact: { ...fact, ...overrides }, backUrl: '/cp/activity' },
    });
}

describe('a single fact', () => {
    it('renders a stored mustache as text rather than evaluating it', () => {
        const wrapper = page({ event_type: '{{ 1 + 1 }}', source: '{{ 1 + 1 }}' });

        // If it were compiled as an expression this would read "2", and if it
        // were dropped the page would render blank — both were live failure
        // modes on the Blade shell.
        expect(wrapper.text()).toContain('{{ 1 + 1 }}');
        expect(wrapper.text()).not.toContain(' 2 ');
    });

    it('escapes markup in a stored value', () => {
        const wrapper = page({ source: '<script>alert(1)</script>' });

        expect(wrapper.html()).not.toContain('<script>alert(1)</script>');
        expect(wrapper.text()).toContain('<script>alert(1)</script>');
    });

    it('titles itself after the fact it is showing', () => {
        expect(page().findComponent({ name: 'Head' }).attributes('data-attr-title'))
            .toBe('commerce.purchase_completed · 42');
    });

    it('offers a way back to the ledger', () => {
        const back = page().findComponent({ name: 'Button' });

        expect(back.attributes('data-attr-href')).toBe('/cp/activity');
        expect(back.attributes('data-attr-icon')).toBe('arrow-left');
    });

    it('puts that way back into the command palette', () => {
        // Every core page-level action is a palette entry; skipping it is what
        // makes an addon feel inert next to core.
        const entry = page().findComponent({ name: 'CommandPaletteItem' });

        expect(entry.attributes('data-attr-category')).toBe('Actions');
        expect(entry.attributes('data-attr-url')).toBe('/cp/activity');
    });

    it('shows a missing value as a dash rather than as a gap', () => {
        // A row that quietly disappears reads as "not shown"; a dash reads as
        // "not recorded". The distinction matters on a ledger.
        expect(page({ dedupe_key: null }).text()).toContain('—');
    });

    it('joins actor type and id the way the ledger stores them', () => {
        expect(page().text()).toContain('user · 7');
        expect(page({ actor_id: null }).text()).toContain('user');
    });

    it('pretty-prints properties and context', () => {
        const text = page().text();

        expect(text).toContain('"product": "stimmnotfallplan"');
        expect(text).toContain('"ip": "127.0.0.1"');
    });

    it('renders null properties without throwing', () => {
        expect(page({ properties: null, context: null }).text()).toContain('null');
    });

    it('hides the subject panel when nothing was recorded against a subject', () => {
        expect(findByAttr(page(), 'Panel', 'heading', 'activity::cp.detail_subject'))
            .toBeUndefined();

        expect(findByAttr(
            page({ subject_type: 'App\\Models\\Order', subject_id: '9' }),
            'Panel', 'heading', 'activity::cp.detail_subject',
        )).toBeDefined();
    });

    it('flags an anonymised fact', () => {
        expect(page().findComponent({ name: 'Badge' }).exists()).toBe(false);

        const badge = page({ anonymized: true }).findComponent({ name: 'Badge' });

        expect(badge.attributes('data-attr-color')).toBe('amber');
        expect(visibleText(page({ anonymized: true })))
            .toContain('activity::cp.anonymized_notice');
    });
});
