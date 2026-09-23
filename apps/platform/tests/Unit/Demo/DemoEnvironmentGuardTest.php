<?php

namespace Tests\Unit\Demo;

use Database\Seeders\Demo\DemoEnvironmentGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The local DDEV demo creates fixed-password accounts, so its guard must
 * refuse every environment except a developer's own DDEV container.
 */
class DemoEnvironmentGuardTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private static function ddevConnections(): array
    {
        return [
            'pgsql' => ['host' => 'db', 'database' => 'db', 'url' => null],
            'pgsql_admin' => ['host' => 'db', 'database' => 'db', 'url' => null],
        ];
    }

    #[Test]
    public function it_allows_the_local_ddev_demo_database(): void
    {
        DemoEnvironmentGuard::assertLocalDdev('local', 'true', self::ddevConnections());

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{0: string, 1: ?string, 2: array<string, mixed>}>
     */
    public static function refusedCases(): iterable
    {
        $ddev = self::ddevConnections();

        yield 'production environment' => ['production', 'true', $ddev];
        yield 'staging environment' => ['staging', 'true', $ddev];
        yield 'testing environment' => ['testing', 'true', $ddev];
        yield 'local but not inside DDEV' => ['local', null, $ddev];
        yield 'local with IS_DDEV_PROJECT=false' => ['local', 'false', $ddev];
        yield 'runtime connection on another host' => ['local', 'true', array_replace($ddev, ['pgsql' => ['host' => '127.0.0.1', 'database' => 'db', 'url' => null]])];
        yield 'runtime connection on another database' => ['local', 'true', array_replace($ddev, ['pgsql' => ['host' => 'db', 'database' => 'school_os', 'url' => null]])];
        yield 'admin connection on another host' => ['local', 'true', array_replace($ddev, ['pgsql_admin' => ['host' => 'prod-db.internal', 'database' => 'db', 'url' => null]])];
        yield 'connection configured by URL' => ['local', 'true', array_replace($ddev, ['pgsql' => ['host' => 'db', 'database' => 'db', 'url' => 'pgsql://user:pass@elsewhere/db']])];
        yield 'admin connection missing' => ['local', 'true', ['pgsql' => $ddev['pgsql']]];
    }

    /**
     * @param  array<string, mixed>  $connections
     */
    #[Test]
    #[DataProvider('refusedCases')]
    public function it_refuses_anything_that_is_not_the_local_ddev_demo(string $environment, ?string $isDdev, array $connections): void
    {
        $this->expectException(RuntimeException::class);

        DemoEnvironmentGuard::assertLocalDdev($environment, $isDdev, $connections);
    }

    #[Test]
    public function the_builder_guard_allows_testing_only_against_the_dedicated_test_database(): void
    {
        $test = [
            'pgsql' => ['host' => '127.0.0.1', 'database' => 'school_os_test'],
            'pgsql_admin' => ['host' => '127.0.0.1', 'database' => 'school_os_test'],
        ];

        DemoEnvironmentGuard::assertLocalDdevOrTesting('testing', null, $test, 'school_os_test');
        $this->addToAssertionCount(1);

        $this->expectException(RuntimeException::class);
        DemoEnvironmentGuard::assertLocalDdevOrTesting('testing', null, array_replace($test, ['pgsql_admin' => ['host' => '127.0.0.1', 'database' => 'school_os']]), 'school_os_test');
    }

    #[Test]
    public function the_builder_guard_still_refuses_production(): void
    {
        $this->expectException(RuntimeException::class);

        DemoEnvironmentGuard::assertLocalDdevOrTesting('production', 'true', self::ddevConnections(), 'school_os_test');
    }
}
