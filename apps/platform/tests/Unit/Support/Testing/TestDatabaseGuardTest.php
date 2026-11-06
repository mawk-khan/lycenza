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

    #[Test]
    public function a_connection_url_on_any_guarded_connection_is_refused_without_naming_it(): void
    {
        $url = 'postgres://someone:a-password@127.0.0.1:1/school_os';

        foreach (['pgsql', 'pgsql_admin', 'pgsql_retention'] as $connection) {
            Config::set("database.connections.{$connection}.url", $url);

            try {
                (new TestDatabaseGuard)->assertSafe();
                $this->fail("{$connection}: a connection URL must be refused");
            } catch (UnsafeTestDatabaseException $e) {
                $this->assertStringContainsString("connection '{$connection}' has a connection URL", $e->getMessage());
                $this->assertStringNotContainsString('a-password', $e->getMessage());
            }

            Config::set("database.connections.{$connection}.url", '');
        }

        (new TestDatabaseGuard)->assertSafe();
    }
}
