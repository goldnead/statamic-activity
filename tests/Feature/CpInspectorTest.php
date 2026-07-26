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

it('lists facts for a permitted user', function (): void {
    permitted();

    Activity::record('commerce.purchase_completed', ['properties' => ['product' => 'stimmnotfallplan']]);

    $this->get('/cp/activity')
        ->assertOk()
        ->assertSee('commerce.purchase_completed');
});

it('filters by event type', function (): void {
    permitted();

    Activity::record('commerce.purchase_completed');
    Activity::record('account.login');

    $this->get('/cp/activity?event_type=account.login')
        ->assertOk()
        ->assertSee('account.login')
        ->assertDontSee('commerce.purchase_completed');
});

it('filters by contact uuid', function (): void {
    permitted();

    Activity::record('commerce.purchase_completed', ['contact_uuid' => 'c-1']);
    Activity::record('account.login', ['contact_uuid' => 'c-2']);

    $this->get('/cp/activity?contact_uuid=c-1')
        ->assertOk()
        ->assertSee('commerce.purchase_completed')
        ->assertDontSee('account.login');
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

it('cannot read another brand\'s fact by guessing its id', function (): void {
    permitted();
    $this->enableMultiBrand();
    $brandA = $this->makeBrand('brand-a');
    $brandB = $this->makeBrand('brand-b');

    $inA = BrandContext::runFor($brandA, fn () => Activity::record('commerce.purchase_completed'));

    BrandContext::setCurrent($brandB);

    $this->get('/cp/activity/'.$inA->id)->assertNotFound();
});
