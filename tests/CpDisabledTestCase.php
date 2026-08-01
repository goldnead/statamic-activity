<?php

namespace Goldnead\Activity\Tests;

/**
 * The same bed with `activity.cp.enabled` off. It has to be its own class
 * because routes are registered at boot: flipping the flag inside a test body
 * happens long after the router has already been built.
 */
abstract class CpDisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('activity.cp.enabled', false);
    }
}
