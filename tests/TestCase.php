<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Simulate every test request as coming from the SPA's own origin, since
     * Sanctum's stateful (session-based) auth only activates for requests it
     * recognizes as "from the frontend" via the Referer/Origin header.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost');
    }
}
