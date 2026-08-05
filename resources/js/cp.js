/**
 * Activity — Statamic 6 Control Panel entry point.
 *
 * Each page registered here corresponds to an `Inertia::render('activity::...')`
 * call on the PHP side. The string identifier MUST match exactly.
 */

import Index from './pages/Index.vue';
import Show from './pages/Show.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('activity::Index', Index);
    Statamic.$inertia.register('activity::Show', Show);
});
