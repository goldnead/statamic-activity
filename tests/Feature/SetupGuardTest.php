<?php

use Goldnead\Activity\Facades\Activity;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use Statamic\Facades\User;

/**
 * An addon installed without its migrations is the ordinary state of a fresh
 * site and of a demo. Until now that state answered HTTP 500 on /cp/activity,
 * because the page's first act is a query against `activities`. These tests
 * reproduce it — a database with everything in it except the ledger's own
 * table — and hold the CP to a readable page plus a line in the log.
 */
beforeEach(function (): void {
    $user = User::make()->email('setup@example.test')->makeSuper();
    $user->save();

    $this->actingAs($user);
});

function dropTheLedgerTable(): void
{
    Schema::dropIfExists('activities');
}

it('answers 200 on the listing when the table is missing', function (): void {
    dropTheLedgerTable();

    $this->get('/cp/activity')->assertOk();
});

it('renders the setup screen and names the missing table', function (): void {
    dropTheLedgerTable();

    $this->get('/cp/activity')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('activity::SetupRequired')
            ->where('tables', ['activities'])
            ->whereNot('heading', '')
            ->whereNot('description', '')
        );
});

/**
 * The point of the guard is a readable page, not a quiet one. If this test ever
 * goes red the addon has traded a visible 500 for a silent nothing, and the
 * site looks installed while it records nothing at all.
 */
it('writes the reason to the log', function (): void {
    dropTheLedgerTable();

    Log::spy();

    $this->get('/cp/activity')->assertOk();

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message) => str_contains($message, 'statamic-activity')
            && str_contains($message, 'activities')
            && str_contains($message, 'php artisan migrate'))
        ->once();
});

/**
 * The listing fetches its rows from the same route. A tab that was open when
 * the table went missing must get the listing contract back, not an Inertia
 * page it cannot render into a table — and the reason still has to reach the
 * log, which is why the guard runs before this branch rather than after it.
 */
it('answers the listing contract with an empty result set when the table is missing', function (): void {
    dropTheLedgerTable();

    Log::spy();

    $response = $this->getJson('/cp/activity')->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.total'))->toBe(0)
        ->and($response->json('meta.columns'))->not->toBeEmpty();

    Log::shouldHaveReceived('error')->once();
});

it('answers 200 on the detail screen when the table is missing', function (): void {
    dropTheLedgerTable();

    $this->get('/cp/activity/1')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('activity::SetupRequired'));
});

it('still renders the listing on a migrated install', function (): void {
    Activity::record('commerce.purchase_completed');

    $this->get('/cp/activity')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('activity::Index'));

    $this->getJson('/cp/activity')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});
