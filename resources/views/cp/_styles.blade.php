{{--
    Scoped styling for the inspector pages.

    Statamic 6's Control Panel is a Vue component library, not a utility-class
    framework: its stylesheet ships only the classes its own source uses. An
    addon's Blade file is never scanned, so `mb-4`, `flex`, `gap-3`,
    `btn-primary` and friends simply do not exist at runtime — the markup
    renders, unstyled, inside an otherwise native page. (`card`, `data-table`
    and `input-text` DO exist and are used as-is above.)

    Everything below is therefore derived from the CP's own design tokens
    (`--radius-*`, `--text-*`, `--color-primary`) and from `currentColor`, so it
    follows the active CP theme in both light and dark mode without hardcoding a
    palette.
--}}
<style>
    .activity-inspector__filters {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        gap: .75rem;
    }

    .activity-inspector__field {
        display: flex;
        flex-direction: column;
        gap: .25rem;
    }

    .activity-inspector__label {
        font-size: var(--text-xs, .75rem);
        font-weight: 500;
        opacity: .7;
    }

    .activity-inspector__input {
        border: 1px solid color-mix(in srgb, currentColor 22%, transparent);
        border-radius: var(--radius-md, .375rem);
        background: color-mix(in srgb, currentColor 6%, transparent);
        color: inherit;
        padding: .375rem .625rem;
        font-size: var(--text-sm, .875rem);
        min-width: 9rem;
    }

    .activity-inspector__input:focus {
        outline: 2px solid var(--color-primary, #6366f1);
        outline-offset: 1px;
    }

    .activity-inspector__button {
        border-radius: var(--radius-md, .375rem);
        padding: .375rem .875rem;
        font-size: var(--text-sm, .875rem);
        font-weight: 500;
        border: 1px solid transparent;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
    }

    .activity-inspector__button--primary {
        background: var(--color-primary, #6366f1);
        color: #fff;
    }

    .activity-inspector__button--plain {
        border-color: color-mix(in srgb, currentColor 22%, transparent);
        color: inherit;
    }

    .activity-inspector__header { margin-bottom: 1.5rem; }
    .activity-inspector__intro { font-size: var(--text-sm, .875rem); opacity: .7; margin-top: .25rem; }
    .activity-inspector__panel { margin-bottom: 1rem; padding: 1rem; }
    .activity-inspector__scroll { overflow-x: auto; }
    .activity-inspector__empty { padding: 1.5rem; text-align: center; opacity: .7; }
    .activity-inspector__pagination { margin-top: 1rem; }
    .activity-inspector__muted { opacity: .6; }

    .activity-inspector__badge {
        display: inline-block;
        border-radius: var(--radius-sm, .25rem);
        padding: .0625rem .375rem;
        font-size: var(--text-2xs, .6875rem);
        background: color-mix(in srgb, currentColor 12%, transparent);
    }

    .activity-inspector__definitions dt {
        font-size: var(--text-xs, .75rem);
        opacity: .6;
    }

    .activity-inspector__definitions dd {
        margin: 0 0 .5rem 0;
        font-size: var(--text-sm, .875rem);
    }

    .activity-inspector__pre {
        font-size: var(--text-xs, .75rem);
        overflow-x: auto;
        margin: 0;
    }
</style>
