<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every call to cart or pricing must be faked; a missing fake fails the test instead of hitting the network.
        Http::preventStrayRequests();
    }
}
