<?php

namespace Goldnead\Activity\Tests\Feature;

use Goldnead\Activity\Facades\Activity;
use Goldnead\Activity\Tests\CpDisabledTestCase;
use Statamic\Facades\User;

/**
 * A class-based test rather than a Pest one: the bed differs from the rest of
 * the Feature suite, and Pest binds a single test case per directory.
 */
class CpDisabledTest extends CpDisabledTestCase
{
    /**
     * Until now the flag hid the nav item and left both screens reachable by
     * URL, which is not a disabled Control Panel — it is a hidden one.
     */
    public function test_it_takes_the_screens_away_not_just_the_nav_item(): void
    {
        $user = User::make()->email('disabled@example.com')->makeSuper();
        $user->save();
        $this->actingAs($user);

        $activity = Activity::record('commerce.purchase_completed');

        $this->get('/cp/activity')->assertNotFound();
        $this->get('/cp/activity/'.$activity->id)->assertNotFound();
    }
}
