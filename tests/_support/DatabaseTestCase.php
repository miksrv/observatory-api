<?php

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * Base class for every test that touches the database.
 *
 * The feature tests empty the application tables in setUp() with raw
 * `DELETE FROM`. That is fine against a throwaway database and catastrophic
 * against any other: on 2026-09-22 the suite was run with the connection
 * pointing at the working database and wiped a full test-run's worth of
 * frames, sources and anomalies in a few seconds. Two things made that
 * possible — `phpunit.xml.dist` naming the working database for the `tests`
 * group, and the tests asking for the `default` group by name, which
 * sidesteps CodeIgniter's own "use `tests` when ENVIRONMENT is testing"
 * safeguard entirely.
 *
 * This class closes both: tests take their connection from `db()` (the
 * `tests` group), and setUp() refuses to run unless that connection's
 * database name ends in `_test`. See README.md, "Tests", for how to create
 * and migrate `db_test`.
 */
abstract class DatabaseTestCase extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $name = $this->db()->getDatabase();

        if (! str_ends_with($name, '_test')) {
            self::fail(sprintf(
                "Refusing to run database tests against '%s': the test connection must point at a "
                . "database whose name ends in '_test' (these tests DELETE FROM every application "
                . "table in setUp()). Set database.tests.database in .env / phpunit.xml to db_test — "
                . 'see README.md, "Tests".',
                $name,
            ));
        }
    }

    /**
     * The chart upload directory, created on demand. Tests that plant chart
     * files directly (instead of uploading through the API, whose controller
     * creates the directory itself) must go through this: writable/uploads/*
     * is git-ignored, so a fresh checkout — CI — has no charts/ directory
     * and a bare file_put_contents() there fails.
     */
    protected function chartsDir(): string
    {
        $dir = WRITEPATH . 'uploads/charts';

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            self::fail("Could not create chart directory {$dir}");
        }

        return $dir . '/';
    }

    /**
     * The test-group connection — the same one the controllers under test use
     * when ENVIRONMENT is 'testing'. Never ask for 'default' by name here.
     */
    protected function db(): \CodeIgniter\Database\BaseConnection
    {
        return \Config\Database::connect();
    }
}
