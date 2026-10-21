<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * RefreshDatabase drops every table. Refuse to run against a MySQL
     * database that isn't a test database, e.g. inside the Docker app
     * container, where DB_DATABASE points at the demo data.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' && ! str_ends_with($database, '_test')) {
            throw new RuntimeException("Tests must use a database whose name ends in _test; [{$database}] on [{$connection}] was configured.");
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // The array store starts empty for every test; Redis keeps its data,
        // so cached reports, rate limits and circuit state would leak from
        // one test into the next.
        if (config('cache.default') !== 'array') {
            Cache::flush();
        }
    }
}
