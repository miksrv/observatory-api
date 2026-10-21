<?php

namespace Tests\Unit;

use App\Libraries\DbRetry;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * @internal
 */
final class DbRetryTest extends CIUnitTestCase
{
    public function testDeadlockAndLockWaitTimeoutAreLockConflicts(): void
    {
        $this->assertTrue(DbRetry::isLockConflict(new DatabaseException('Deadlock found', 1213)));
        $this->assertTrue(DbRetry::isLockConflict(new DatabaseException('Lock wait timeout exceeded', 1205)));
        // CodeIgniter rewraps the driver's exception; the code may sit on a wrapped one.
        $this->assertTrue(DbRetry::isLockConflict(new DatabaseException('x', 0, new RuntimeException('Deadlock', 1213))));
        $this->assertFalse(DbRetry::isLockConflict(new DatabaseException('Duplicate entry', 1062)));
        $this->assertFalse(DbRetry::isLockConflict(new RuntimeException('other')));
    }

    public function testALockConflictIsRetriedAndTheStateRestored(): void
    {
        $calls    = 0;
        $restored = [];

        $result = DbRetry::onLockConflict(
            static function () use (&$calls): string {
                if (++$calls < 3) {
                    throw new DatabaseException('Deadlock found', 1213);
                }

                return 'done';
            },
            3,
            static function (int $n) use (&$restored): void {
                $restored[] = $n;
            },
            0,
        );

        $this->assertSame('done', $result);
        $this->assertSame(3, $calls);
        $this->assertSame([1, 2], $restored);
    }

    public function testItGivesUpAfterTheLastAttempt(): void
    {
        $calls = 0;

        try {
            DbRetry::onLockConflict(static function () use (&$calls): void {
                $calls++;

                throw new DatabaseException('Lock wait timeout exceeded', 1205);
            }, 3, null, 0);
            $this->fail('The last lock conflict must be rethrown.');
        } catch (DatabaseException $e) {
            $this->assertSame(1205, $e->getCode());
        }

        $this->assertSame(3, $calls);
    }

    public function testOtherErrorsAreNotRetried(): void
    {
        $calls = 0;

        $this->expectException(DatabaseException::class);

        try {
            DbRetry::onLockConflict(static function () use (&$calls): void {
                $calls++;

                throw new DatabaseException('Duplicate entry', 1062);
            }, 3, null, 0);
        } finally {
            $this->assertSame(1, $calls);
        }
    }
}
