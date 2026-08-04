<?php

use Goldnead\Activity\Facades\Activity;
use Goldnead\BrandContext\Facades\BrandContext;
use Inertia\Testing\AssertableInertia;
use Statamic\Facades\User;

/** A super user passes every permission check — the inspector's gate is what is under test elsewhere. */
function permitted(): void
{
    $user = User::make()->email('cp@example.com')->makeSuper();
    $user->save();

    test()->actingAs($user);
}

it('refuses access without the view permission', function (): void {
    Activity::record('commerce.purchase_completed');

    $this->get('/cp/activity')->assertForbidden();
});

it('boots the native listing rather than a hand-built table', function (): void {
    permitted();

    Activity::record('commerce.purchase_completed');

    // The screen is an Inertia page now: the server ships props, the registered
    // Vue component ships the markup. Asserting on the component name and the
    // props is asserting on the whole of what the server is responsible for —
    // there is no server-rendered chrome left to check.
    $this->get('/cp/activity')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('activity::Index')
            ->where('hasAny', true)
            ->where('listingUrl', cp_route('activity.index'))
            ->has('columns')
            ->has('filters')
        );
});

it('passes the listing well-formed props', function (): void {
    permitted();

    Activity::record('commerce.purchase_completed');

    $this->get('/cp/activity')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('activity::Index')
            ->where('columns', fn ($columns) => collect($columns)->pluck('field')->all()
                === ['occurred_at', 'event_type', 'actor', 'source'])
            ->has('deepLinkParameters')
            ->where('perPage', fn ($perPage) => is_int($perPage) && $perPage > 0)
        );
});

it('carries a deep link through to the listing as an additional parameter', function (): void {
    permitted();

    Activity::record('commerce.purchase_completed', ['contact_uuid' => 'c-1']);

    $this->get('/cp/activity?contact_uuid=c-1')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('deepLinkParameters', ['contact_uuid' => 'c-1'])
        );
});

it('shows the native empty state before anything has been recorded', function (): void {
    permitted();

    // `hasAny` is the whole of the empty-state decision; the page picks the
    // centred header and EmptyStateMenu off it.
    $this->get('/cp/activity')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('activity::Index')
            ->where('hasAny', false)
        );
});

it('shows a single fact', function (): void {
    permitted();

    $activity = Activity::record('commerce.purchase_completed', [
        'properties' => ['product' => 'stimmnotfallplan'],
    ]);

    $this->get('/cp/activity/'.$activity->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('activity::Show')
            ->where('fact.id', $activity->id)
            ->where('fact.event_type', 'commerce.purchase_completed')
            ->where('fact.event_id', $activity->event_id)
            ->where('fact.properties.product', 'stimmnotfallplan')
        );
});

it('offers a way back to the ledger from a fact', function (): void {
    permitted();

    $activity = Activity::record('commerce.purchase_completed');

    $this->get('/cp/activity/'.$activity->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('backUrl', cp_route('activity.index'))
        );
});

/**
 * The detail payload is assembled field by field on purpose. Handing the model
 * to Inertia would put whatever the table happens to carry into a prop the
 * browser can read — brand scoping columns today, whatever a migration adds
 * tomorrow. This pins the contract so a widened table cannot widen the page.
 */
it('ships only the fields the detail screen shows', function (): void {
    permitted();

    $activity = Activity::record('commerce.purchase_completed');

    $this->get('/cp/activity/'.$activity->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            // No ->etc(): AssertableInertia then insists every key was named,
            // so this is the exact set, not a subset.
            ->has('fact', fn (AssertableInertia $fact) => $fact
                ->hasAll([
                    'id', 'event_type', 'event_id', 'dedupe_key', 'occurred_at', 'received_at',
                    'source', 'anonymized', 'actor_type', 'actor_id', 'contact_uuid', 'user_id',
                    'anonymous_id', 'subject_type', 'subject_id', 'properties', 'context',
                ])
            )
            ->etc()
        );
});

/**
 * Statamic 5 chrome and the Blade compatibility shell are both gone. This is a
 * cheap guard that nobody reintroduces either while "just adding one screen".
 */
it('serves the control panel without any legacy shell', function (): void {
    permitted();

    Activity::record('commerce.purchase_completed');

    $html = $this->get('/cp/activity')->assertOk()->getContent();

    expect($html)
        ->not->toContain('class="card"')
        ->not->toContain('activity-inspector__')
        ->and($html)->toContain('data-page');
});

/**
 * Stored values used to reach the browser as a Vue template — the Blade screens
 * rendered inside the NonInertiaPage shim, so a property containing a mustache
 * was compiled as an expression unless the element carried v-pre. An Inertia
 * page has no such seam: values travel as JSON props and are interpolated by a
 * compiled component, which escapes them. This asserts the value survives the
 * trip intact rather than being evaluated or stripped.
 */
it('carries a mustache in a stored value through as literal data', function (): void {
    permitted();

    $mustache = '{{ 1 + 1 }}';

    $activity = Activity::record($mustache, [
        'source' => $mustache,
        'contact_uuid' => $mustache,
        'user_id' => $mustache,
        'anonymous_id' => $mustache,
        'subject_type' => $mustache,
        'subject_id' => $mustache,
        'dedupe_key' => $mustache,
        'properties' => ['note' => $mustache],
        'context' => ['note' => $mustache],
    ]);

    $this->get('/cp/activity/'.$activity->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('fact.event_type', $mustache)
            ->where('fact.source', $mustache)
            ->where('fact.dedupe_key', $mustache)
            ->where('fact.properties.note', $mustache)
            ->where('fact.context.note', $mustache)
        );
});

it('keeps a hostile stored value out of the page as markup', function (): void {
    permitted();

    // These reach the page as JSON inside the data-page attribute: the filter
    // option lists are built from distinct event types and sources.
    Activity::record('"><script>alert(1)</script>', ['source' => "it's {{ 1 + 1 }}"]);

    $html = $this->get('/cp/activity')->assertOk()->getContent();

    expect($html)->not->toContain('<script>alert(1)</script>');

    $this->get('/cp/activity')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters', fn ($filters) => collect($filters)->isNotEmpty())
        );
});

it('cannot read another brand\'s fact by guessing its id', function (): void {
    permitted();
    $this->enableMultiBrand();
    $brandA = $this->makeBrand('brand-a');
    $brandB = $this->makeBrand('brand-b');

    $inA = BrandContext::runFor($brandA, fn () => Activity::record('commerce.purchase_completed'));

    BrandContext::setCurrent($brandB);

    $this->get('/cp/activity/'.$inA->id)->assertNotFound();
});
