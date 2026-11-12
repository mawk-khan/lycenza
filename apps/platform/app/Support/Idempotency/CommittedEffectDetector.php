<?php

namespace App\Support\Idempotency;

use Illuminate\Database\Connection;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * HR.R1-C30 (C14-N2, Platform owner decision C30-A, 2026-11-12): did the request
 * that just failed leave anything behind? EnsureIdempotent releases a rendered 5xx
 * only when the answer is a proven no; otherwise the failure stays remembered and
 * a retry never runs the operation again (C30-B). Doubt counts as yes.
 *
 * A committed effect is any of:
 * - a write statement ATTEMPTED outside any transaction the request opened
 *   (autocommit), whether or not it reported success: a lost acknowledgement may
 *   hide a write the server committed (review MEDIUM-1). Statements are judged
 *   before they run (`beforeExecuting`, on every connection);
 * - a write inside a transaction level that was later committed back to the level
 *   the request started at. A rolled-back level's writes are discarded;
 * - PostgreSQL's own answer just before the outermost COMMIT: a transaction that
 *   was given an id has written, or locked rows (`pg_current_xact_id_if_assigned()`);
 * - a job about to be queued (`JobQueueing`, before the push), an outbound HTTP
 *   request about to be sent, or a mail message about to be sent.
 *
 * A statement is a write unless it is a plain read: one SELECT (or CTE, VALUES,
 * TABLE) with no data change, no INTO and no second statement, or SHOW, SET,
 * RESET, DISCARD, DEALLOCATE, a plain EXPLAIN. A SELECT is a write too when it
 * calls a VOLATILE function of the `public` schema, or a writer of `pg_catalog`
 * (`setval`, large objects). Anything it cannot classify, or a failure to load the
 * function list, counts as a write. What it cannot see: bytes written to object
 * storage, which hold no business record of their own (rule 86).
 *
 * One request at a time per process (PHP-FPM, artisan): a scope is opened by
 * begin() and closed by end(), and the hooks do nothing outside one.
 */
final class CommittedEffectDetector
{
    private const CATALOG_WRITERS = 'setval|lo_import|lo_export|lo_unlink|lo_create|lo_creat|lo_from_bytea|lo_put|lo_truncate|lo_open|pg_notify';

    private bool $watching = false;

    private bool $committed = false;

    /** @var array<int, int> per Connection object, the transaction level it was at when the request began */
    private array $baseline = [];

    /** @var array<int, array<int, true>> per Connection object, the open levels that wrote */
    private array $dirty = [];

    private bool $listening = false;

    /**
     * Connections whose statements are classified before they run, by object: `DB::purge()` replaces the object
     * under the same name (delta review L1), and the new one is hooked when it is established.
     *
     * @var \WeakMap<Connection, true>
     */
    private \WeakMap $hooked;

    public function __construct()
    {
        $this->hooked = new \WeakMap;
    }

    /** A regex naming every VOLATILE `public` function, or null when the list could not be read (doubt). */
    private static ?string $volatilePattern = null;

    private static bool $volatileLoaded = false;

    public function begin(): void
    {
        $this->listen();
        $this->loadVolatileFunctions();
        $this->committed = false;
        $this->dirty = [];
        $this->baseline = [];
        foreach (DB::getConnections() as $connection) {
            $this->baseline[spl_object_id($connection)] = $connection->transactionLevel();
            $this->hook($connection);
        }
        $this->watching = true;
    }

    /** True when the request left a committed effect, or the detector cannot tell. */
    public function end(): bool
    {
        $this->watching = false;
        $committed = $this->committed || self::$volatilePattern === null;
        $this->dirty = [];

        return $committed;
    }

    private function listen(): void
    {
        if ($this->listening) {
            return;
        }
        $this->listening = true;

        // A connection first opened during the request is hooked as it opens.
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $this->hook($event->connection);
        });
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            if (! $this->watching) {
                return;
            }
            $id = spl_object_id($event->connection);
            $after = $event->connection->transactionLevel();
            if (isset($this->dirty[$id][$after + 1])) {
                unset($this->dirty[$id][$after + 1]);
                $after <= $this->baselineOf($id) ? $this->committed = true : $this->dirty[$id][$after] = true;
            }
        });
        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            if (! $this->watching) {
                return;
            }
            $id = spl_object_id($event->connection);
            $after = $event->connection->transactionLevel();
            foreach (array_keys($this->dirty[$id] ?? []) as $level) {
                if ($level > $after) {
                    unset($this->dirty[$id][$level]);
                }
            }
        });
        // Fired only before the outermost commit, so a real COMMIT is about to happen.
        Event::listen(TransactionCommitting::class, function (TransactionCommitting $event): void {
            if (! $this->watching || $this->committed || $event->connection->getDriverName() !== 'pgsql') {
                return;
            }
            try {
                $wrote = (bool) ($event->connection->selectOne('select pg_current_xact_id_if_assigned() is not null as wrote')->wrote ?? true);
            } catch (Throwable) {
                $wrote = true;
            }
            if ($wrote) {
                $this->committed = true;
            }
        });
        foreach ([JobQueueing::class, JobQueued::class, RequestSending::class, MessageSending::class] as $sideEffect) {
            Event::listen($sideEffect, function (): void {
                if ($this->watching) {
                    $this->committed = true;
                }
            });
        }
    }

    private function hook(Connection $connection): void
    {
        if (isset($this->hooked[$connection])) {
            return;
        }
        $this->hooked[$connection] = true;
        $connection->beforeExecuting(function (string $query, array $bindings, Connection $connection): void {
            if ($this->watching && $this->isWrite($query)) {
                $this->wrote($connection);
            }
        });
    }

    private function wrote(Connection $connection): void
    {
        $id = spl_object_id($connection);
        $level = $connection->transactionLevel();
        if ($level <= $this->baselineOf($id)) {
            // Autocommit: the statement commits as it runs, reported or not.
            $this->committed = true;

            return;
        }
        $this->dirty[$id][$level] = true;
    }

    private function baselineOf(int $id): int
    {
        // A connection first opened during the request started outside any transaction.
        return $this->baseline[$id] ??= 0;
    }

    public function isWrite(string $sql): bool
    {
        $statement = strtolower(trim(ltrim($sql, " \t\n\r(")));
        // More than one statement: never judged by its first.
        if (str_contains(rtrim($statement, "; \t\n\r"), ';')) {
            return true;
        }
        if (preg_match('/^(show|set|reset|discard|deallocate|savepoint|release|rollback|begin|start|commit)\b/', $statement) === 1) {
            return false;
        }
        if (preg_match('/^explain\b/', $statement) === 1) {
            // EXPLAIN ANALYZE runs the statement it explains.
            return preg_match('/^explain\s*(\([^)]*analy[sz]e|analy[sz]e)/', $statement) !== 0;
        }
        if (preg_match('/^(select|with|values|table)\b/', $statement) !== 1) {
            return true;
        }
        // A row lock is not a write (`FOR [NO KEY] UPDATE`, `FOR [KEY] SHARE`); a committed one is still seen at COMMIT.
        $statement = preg_replace('/\bfor\s+(no\s+key\s+update|update|key\s+share|share)\b/', ' ', $statement);
        if ($statement === null) {
            return true; // A regex error is doubt (delta review L2).
        }
        if (preg_match('/\b(insert|update|delete|merge|into)\b/', $statement) !== 0) {
            return true;
        }
        if (preg_match('/\b(?:pg_catalog\.)?(?:'.self::CATALOG_WRITERS.')\s*\(/', $statement) !== 0) {
            return true;
        }

        return self::$volatilePattern !== null && self::$volatilePattern !== '' && preg_match(self::$volatilePattern, $statement) !== 0;
    }

    private function loadVolatileFunctions(): void
    {
        if (self::$volatileLoaded) {
            return;
        }
        self::$volatileLoaded = true;
        try {
            $names = DB::table('pg_proc')
                ->where('pronamespace', DB::raw("'public'::regnamespace"))
                ->where('provolatile', 'v')
                ->where('prorettype', '<>', DB::raw("'trigger'::regtype"))
                ->distinct()->pluck('proname')->map(fn ($name) => preg_quote(strtolower((string) $name), '/'))->all();
            // Bare, schema-qualified or quoted, followed by its argument list.
            self::$volatilePattern = $names === [] ? '' : '/(?:\b|")(?:'.implode('|', $names).')"?\s*\(/';
        } catch (Throwable) {
            // Unknown which SELECT may write: every request counts as having committed (end()).
            self::$volatilePattern = null;
        }
    }
}
