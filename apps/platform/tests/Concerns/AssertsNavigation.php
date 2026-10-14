<?php

namespace Tests\Concerns;

use Closure;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * SWC.1 (ADR 0073 section 6): assertions over the shared Inertia
 * `navigation` prop (App\Support\Navigation\NavigationBuilder), used as
 * `->where('navigation', self::nav(shown: [...], hidden: [...]))`.
 * Keys are StaffNavigation entry keys -- never a role, never a capability.
 */
trait AssertsNavigation
{
    /**
     * @param  list<string>  $shown  entry keys that must be offered
     * @param  list<string>  $hidden  entry keys that must not be offered
     */
    protected static function nav(array $shown = [], array $hidden = []): Closure
    {
        return static function ($navigation) use ($shown, $hidden): bool {
            $keys = self::navigationKeys($navigation);

            foreach ($shown as $key) {
                PHPUnit::assertContains($key, $keys, "navigation entry [{$key}] should be offered");
            }
            foreach ($hidden as $key) {
                PHPUnit::assertNotContains($key, $keys, "navigation entry [{$key}] must not be offered");
            }

            return true;
        };
    }

    /** Whether one entry is offered, for data-driven cases. */
    protected static function navOffers(string $key, bool $offered): Closure
    {
        return $offered ? self::nav(shown: [$key]) : self::nav(hidden: [$key]);
    }

    /**
     * Exactly these entries outside the account bar, in any order.
     *
     * @param  list<string>  $keys
     */
    protected static function navExactly(array $keys): Closure
    {
        return static function ($navigation) use ($keys): bool {
            $actual = self::navigationKeys($navigation, includeAccount: false);
            sort($actual);
            sort($keys);
            PHPUnit::assertSame($keys, $actual);

            return true;
        };
    }

    /** @return list<string> */
    protected static function navigationKeys(mixed $navigation, bool $includeAccount = true): array
    {
        $navigation = is_object($navigation) && method_exists($navigation, 'toArray') ? $navigation->toArray() : (array) $navigation;
        $keys = [];

        foreach ($navigation['groups'] ?? [] as $group) {
            foreach ($group['items'] as $item) {
                $keys[] = $item['key'];
            }
        }

        if ($includeAccount) {
            foreach ($navigation['account'] ?? [] as $item) {
                $keys[] = $item['key'];
            }
        }

        return $keys;
    }
}
