<?php

namespace Tests\Unit\Support\Testing;

use App\Support\Testing\Exceptions\UnsafeTestDatabaseException;
use App\Support\Testing\TestDatabaseGuard;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fast, in-process complement to
 * tests/Feature/Infrastructure/TestEnvironmentSafetyTest.php's
 * subprocess-backed proof: exercises TestDatabaseGuard::assertSafe()
 * directly against simulated resolved configuration (item 21's "unsafe
 * DB such as school_os"), confirming the exact condition it checks
 * without paying for a real child process on every run. Config-only by
 * design (TestDatabaseGuard's own docblock: "never opens a database
 * connection itself"), so this needs no real Postgres round-trip
 * either.
 */
class TestDatabaseGuardTest extends TestCase
{
    #[Test]
    public function it_allows_boot_when_every_guarded_connection_resolves_to_the_approved_test_database(): void
    {
        $approved = config('database.testing_database');
        Config::set('database.connections.pgsql.database', $approved);
        Config::set('database.connections.pgsql_admin.database', $approved);

        (new TestDatabaseGuard)->assertSafe();

        $this->addToAssertionCount(1); // no exception thrown
    }

    #[Test]
    public function it_refuses_when_the_pgsql_connection_resolves_to_the_ordinary_development_database(): void
    {
        Config::set('database.connections.pgsql.database', 'school_os');

        $this->expectException(UnsafeTestDatabaseException::class);
        $this->expectExceptionMessage("not the approved test database 'school_os_test'");

        (new TestDatabaseGuard)->assertSafe();
    }

    #[Test]
    public function it_refuses_when_the_pgsql_admin_connection_resolves_to_the_ordinary_development_database(): void
    {
        $approved = config('database.testing_database');
        Config::set('database.connections.pgsql.database', $approved);
        Config::set('database.connections.pgsql_admin.database', 'school_os');

        $this->expectException(UnsafeTestDatabaseException::class);

        (new TestDatabaseGuard)->assertSafe();
    }
}
