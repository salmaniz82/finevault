<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        // Runs before RefreshDatabase: never allow test migrations to erase the working portal.
        $connection = config('database.default');
        if ($connection !== 'sqlite' && config('database.connections.'.$connection.'.database') !== 'fineworth_portal_relational_test') {
            throw new \RuntimeException('Integration tests require the dedicated fineworth_portal_relational_test database.');
        }
        if ($connection === 'sqlite' && config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('SQLite tests require an in-memory database.');
        }

        return $app;
    }
}
