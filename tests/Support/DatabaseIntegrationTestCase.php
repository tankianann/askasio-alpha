<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseIntegrationTestCase extends TestCase
{
    protected static ?PDO $database = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!TestDatabase::isConfigured()) {
            self::markTestSkipped('Set TEST_DB_DATABASE to a disposable database name ending in _test.');
        }

        if (!self::$database instanceof PDO) {
            self::$database = TestDatabase::recreate();
            TestDatabase::migrate(self::$database);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$database = null;

        if (TestDatabase::isConfigured()) {
            TestDatabase::drop();
        }

        parent::tearDownAfterClass();
    }
}
