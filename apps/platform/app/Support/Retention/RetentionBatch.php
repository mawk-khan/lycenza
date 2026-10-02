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
 *   left), and a table added later blocks too.
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
     * @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int}
     */
    public function prune(string $table, Closure $eligible, int $batch, bool $dryRun, bool $held): array
    {
        $deletable = fn (): Builder => $this->unreferenced($table, $eligible());
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
                [$selected, $deleted] = DB::transaction(function () use ($deletable, $batch): array {
                    $ids = $deletable()->orderBy('t.id')->limit($batch)->lock('FOR UPDATE SKIP LOCKED')->pluck('t.id')->all();

                    return [count($ids), $ids === [] ? 0 : $deletable()->whereIn('t.id', $ids)->delete()];
                });
            } catch (QueryException) {
                $result['errors']++;

                break;
            }
            $result['deleted'] += $deleted;
        } while ($selected === $batch && $deleted > 0);

        return $result;
    }

    /** Narrows `t` to rows no row in any referencing table points at. */
    private function unreferenced(string $table, Builder $query): Builder
    {
        foreach ($this->references->to($table) as $reference) {
            $query->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from($reference['table'])->whereColumn($reference['table'].'.'.$reference['column'], 't.id'));
        }

        return $query;
    }
}
