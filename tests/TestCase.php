<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        require_once __DIR__.'/../tools/testing/guard.php';
        maxs_test_guard();
        // Explicit-migration/process tests leave committed fixtures. Force the next
        // transaction-based test to migrate rather than reuse that dirty database.
        if (!in_array(\Illuminate\Foundation\Testing\RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;
        }
        parent::setUp();
        \App\Support\TestEnvironment::verifyDatabase();
        \App\Support\TestEnvironment::fakeProviders();
    }
}
