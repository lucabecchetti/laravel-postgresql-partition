<?php

namespace Brokenice\LaravelPgsqlPartition\Tests\Unit;

use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase
{
    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Tear down the test environment.
     */
    protected function tearDown(): void
    {
        parent::tearDown();
    }
}
