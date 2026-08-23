<?php

namespace App\Support\Testing\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Support\Testing\TestDatabaseGuard when
 * app()->environment('testing') is true but a connection that could
 * perform destructive operations (`pgsql`, `pgsql_admin`) resolves to
 * a database other than config('database.testing_database'). This
 * halts application boot entirely -- see CLAUDE.md's testing-database
 * safety rules and the Phase 0C.3A incident this closes.
 */
class UnsafeTestDatabaseException extends RuntimeException {}
