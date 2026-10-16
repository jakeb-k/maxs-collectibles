<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        require_once __DIR__.'/../tools/testing/guard.php';
        maxs_test_guard();
        parent::setUp();
        \App\Support\TestEnvironment::verifyDatabase();
        \App\Support\TestEnvironment::fakeProviders();
    }
}
