<?php

namespace Tests\Concerns;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * E21-RH.6 (ADR 0066 §14): the runtime role holds no DELETE on a table only
 * retention deletes from. A raw cross-School DELETE through it is therefore
 * refused by privilege before RLS is even consulted -- a stronger guarantee
 * than "0 rows affected". (The retention identity's own RLS confinement is
 * proven by RetentionIdentityTest / RetentionDeleteBoundaryTest.)
 */
trait AssertsRuntimeDeleteRevoked
{
    /** @param  array<int, mixed>  $bindings */
    protected function assertRuntimeDeleteRevoked(string $sql, array $bindings = []): void
    {
        try {
            // A savepoint: the refusal must not abort the test's own transaction.
            DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->delete($sql, $bindings));
            $this->fail("The runtime role must not delete: {$sql}");
        } catch (QueryException $e) {
            $this->assertStringContainsString('permission denied for table', $e->getMessage());
        }
    }
}
