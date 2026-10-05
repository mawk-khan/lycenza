<?php

namespace App\Support\Retention;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * E21.3D: expires the rows of ONE retention parent table whose own clock
 * has passed, in bounded deterministic batches, and never a row anything
 * still references. The caller (the owning module) supplies the table, a
 * member of ReferencingRows::PARENTS, and its own fixed eligibility query
 * over `<table> as t`; there is no caller-supplied SQL or table beyond that
 * closed list.
 *
 * - "Unreferenced" is read from the live FK catalog: a row any other row
 *   points at is `dependency_blocked` and kept (an attendance register
 *   header with a record left; a timetable entry with a register header
 *   left), and a table added later blocks too. `$owned` names the child
 *   tables the row's own delete removes with it (ON DELETE CASCADE,
 *   pinned by the caller's classification test), e.g. an automation
 *   execution's attempts.
 * - Each batch is its own transaction: lock `FOR UPDATE SKIP LOCKED` (a row
 *   a concurrent insert of a child is holding is skipped), then delete with
 *   the predicate re-applied. A child insert that comes later waits for the
 *   lock and then fails on its foreign key.
 * - Dry run and hold count with the same predicate; a held School deletes
 *   nothing.
 */
final class RetentionBatch
{
    public function __construct(private readonly ReferencingRows $references) {}

    /**
     * @param  Closure(): Builder  $eligible  rows of `<table> as t` past their own clock
     * @param  list<string>  $owned  child tables removed by the row's own cascade
     * @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    public function prune(string $table, Closure $eligible, int $batch, bool $dryRun, bool $held, array $owned = []): array
    {
        // E21-RH.7: only rows the database recorded before the unit's declared cutoff are eligible at all.
        $eligible = RetentionAnchors::anchored($table) ? fn (): Builder => RetentionAnchors::recordedBefore($eligible(), 't') : $eligible;
        $deletable = fn (): Builder => $this->unreferenced($table, $eligible(), $owned);
        $count = $eligible()->count();
        $result = ['eligible' => $count, 'deleted' => 0, 'held' => 0, 'unresolved' => 0, 'dependency_blocked' => $count === 0 ? 0 : $count - $deletable()->count(), 'errors' => 0];

        if ($held) {
            return ['eligible' => $count, 'deleted' => 0, 'held' => $count, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];
        }
        if ($dryRun || $count === $result['dependency_blocked']) {
            return $result;
        }

        do {
            try {
                [$selected, $deleted] = DB::transaction(function () use ($deletable, $batch, $table): array {
                    // E21-RH.6: the retention identity locks through the lock-only definer (FOR UPDATE SKIP LOCKED).
                    $candidates = $deletable()->orderBy('t.id')->limit($batch)->pluck('t.id')->map(fn ($id) => (string) $id)->all();
                    $ids = RetentionLocks::lock($table, $candidates, skipLocked: true);

                    return [count($candidates), $ids === [] ? 0 : $deletable()->whereIn('t.id', $ids)->delete()];
                });
            } catch (QueryException $e) {
                // E21-RH.7: a row recorded within the period (a late child) keeps the batch, never an error.
                $result[RetentionAnchors::refused($e) ? 'dependency_blocked' : 'errors']++;

                break;
            }
            $result['deleted'] += $deleted;
        } while ($selected === $batch && $deleted > 0);

        return $result;
    }

    /**
     * Narrows `t` to rows no row in any referencing table (other than `$owned`) points at.
     *
     * @param  list<string>  $owned
     */
    private function unreferenced(string $table, Builder $query, array $owned = []): Builder
    {
        foreach ($this->references->to($table) as $reference) {
            if (in_array($reference['table'], $owned, true)) {
                continue;
            }
            $query->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from($reference['table'])->whereColumn($reference['table'].'.'.$reference['column'], 't.id'));
        }

        return $query;
    }
}
