<?php

use Goldnead\Activity\Facades\Activity;
use Goldnead\BrandContext\Facades\BrandContext;
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

    $response = $this->get('/cp/activity')->assertOk();

    $response->assertSee('<ui-listing', false);
    $response->assertSee('<ui-header', false);

    // The rows arrive over the JSON contract, so the shell must not also
    // hand-render them, and none of the Statamic 5 chrome may come back.
    $response->assertDontSee('class="card"', false);
    $response->assertDontSee('data-table', false);
    $response->assertDontSee('activity-inspector__', false);
});

it('passes the listing well-formed json props', function (): void {
    permitted();

    Activity::record('commerce.purchase_completed');

    $html = $this->get('/cp/activity')->assertOk()->getContent();

    foreach (['columns', 'filters', 'additional-parameters'] as $prop) {
        expect($html)->toContain(':'.$prop.'="');
    }

    preg_match('/:columns="([^"]*)"/', $html, $matches);

    $columns = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);

    expect($columns)->toBeArray()
        ->and(array_column($columns, 'field'))->toBe(['occurred_at', 'event_type', 'actor', 'source']);
});

it('shows the native empty state before anything has been recorded', function (): void {
    permitted();

    $this->get('/cp/activity')
        ->assertOk()
        ->assertSee('<ui-empty-state-menu', false)
        ->assertDontSee('<ui-listing', false);
});

it('shows a single fact', function (): void {
    permitted();

    $activity = Activity::record('commerce.purchase_completed', [
        'properties' => ['product' => 'stimmnotfallplan'],
    ]);

    $this->get('/cp/activity/'.$activity->id)
        ->assertOk()
        ->assertSee('stimmnotfallplan')
        ->assertSee($activity->event_id);
});

it('titles the detail page after the fact it is showing', function (): void {
    permitted();

    $activity = Activity::record('commerce.purchase_completed');

    $this->get('/cp/activity/'.$activity->id)
        ->assertOk()
        ->assertSee('commerce.purchase_completed · '.$activity->id, false);
});

it('offers a way back to the ledger from a fact', function (): void {
    permitted();

    $activity = Activity::record('commerce.purchase_completed');

    $this->get('/cp/activity/'.$activity->id)
        ->assertOk()
        ->assertSee('href="'.cp_route('activity.index').'"', false);
});

/**
 * The whole yielded block is compiled as a Vue template at runtime, so a stored
 * property containing a mustache would otherwise be evaluated as an expression
 * in the operator's browser. Every element printing ledger content must opt out.
 */
it('never lets a stored property reach the vue compiler as an expression', function (): void {
    permitted();

    $activity = Activity::record('commerce.purchase_completed', [
        'properties' => ['note' => '{{ 7 * 7 }}'],
    ]);

    $html = $this->get('/cp/activity/'.$activity->id)->assertOk()->getContent();

    expect($html)->toContain('{{ 7 * 7 }}');

    // The mustache has to sit inside an element that Vue is told to skip.
    preg_match('/<pre[^>]*>(?:(?!<\/pre>).)*\{\{ 7 \* 7 \}\}/s', $html, $matches);

    expect($matches[0] ?? '')->toContain('v-pre');
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
