<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * Base test case for ســـیــمـا.
 *
 * THIS CLASS IS THE LIVE DEFENSE IN AGAINST A LIVE-DB MIGRATION CASCADE. On
 * 2026-10-21 a bare `php artisan test` inside `sima-sima-app-1` fresh-migrated
 * the LIVE Postgres `sima` database twice: once directly (because the container's
 * baked env (`DB_CONNECTION=pgsql`, `DB_DATABASE=sima`) beats phpunit.xml), and
 * again because the resulting failed build hit the live Redis limiter state
 * (429s). Two layers protect the live corpus now:
 *
 *   1. phpunit.xml <server> entries set `$_SERVER` BEFORE Laravel boots —
 *      Laravel's dotenv repository reads `$_SERVER` first (ServerConstAdapter),
 *      so the in-memory SQLite connection wins over the baked pgsql value. The
 *      `force="true"` entries cover the getenv()/$_ENV path too.
 *
 *   2. This class overrides `createApplication()` to pin `database.default` to
 *      SQLite `:memory:` and `cache.default` to array AFTER the app boots, so
 *      even a misconfigured `vendor/bin/phpunit` (without `--configuration`) is
 *      safe. A final safety check refuses to run if the pinned values are still
 *      live ones — an assertion failure rather than a wipe.
 */
abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.sqlite.driver', 'sqlite');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.array.driver', 'array');
        $app['config']->set('queue.default', 'sync');

        $default = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$default}.database");

        if ($default !== 'sqlite' || $database !== ':memory:') {
            throw new \RuntimeException(sprintf(
                'Refusing to run tests: pinned to sqlite :memory:, but got %s / %s.',
                $default,
                $database
            ));
        }

        return $app;
    }
}
