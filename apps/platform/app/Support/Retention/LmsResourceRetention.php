<?php

namespace App\Support\Retention;

use App\Domain\AcademicStructure\Application\Retention\AcademicYearRetention;
use App\Domain\Documents\Application\Retention\DocumentParentRetention;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * E21.3D (E21.2G A1, project-adopted, pending legal ratification): LMS
 * Learning Content and Assignments are School teaching evidence, kept 7
 * calendar years after the end of their authoritative Academic Year, then
 * deleted with their Section audiences and Documents. Only
 * `platform:academic-retention-prune` calls this, never a request.
 *
 * - The year is the resource's SubjectOffering's (`subject_offerings` are
 *   year-specific, rule 72). Every audience row is pinned to that same
 *   Offering and to a Section of the same year by composite foreign keys,
 *   so a resource has exactly ONE Academic Year: there is no cross-year
 *   audience to reconcile (pinned by a test).
 * - Draft, published and archived resources share the one period.
 * - A teacher-owned resource also needs the E21-D6 minimum for its
 *   embedded owner and audience (EmbeddedAuthorityRetention): the owner's
 *   authority over it last applied at the later of the year's end and the
 *   end of the owner's TeachingAssignments over its audience Sections; an
 *   open one keeps it. Both clocks are computed independently; the longer
 *   one wins. The owner and audience are never removed on their own.
 * - One resource per transaction: lock it, recheck, remove its Documents
 *   (the database function since E21-RH.5; bytes after commit, a failed byte delete is
 *   left to the orphan run), then the audience and the resource through
 *   the fixed, floored database function (RetentionExpiry). A Document or
 *   audience attached concurrently either commits first (it is seen and
 *   handled, or blocks) or waits and then fails on its foreign key.
 * - Any other referencing row keeps it (`dependency_blocked`). A held
 *   School is counted only. Counts only, never a title.
 * - E21-RH.5 (ADR 0066 §13): the whole unit runs as the dedicated retention
 *   identity, on its own connection (RetentionExpiry::privileged()). The
 *   recheck is a plain read; the LMS unit function (RetentionExpiry::lmsResource()) locks the
 *   resource, deletes its Document rows and expires it in the same
 *   transaction, refusing an active School or platform hold itself, so the
 *   retention identity holds no DELETE on `documents`.
 */
final class LmsResourceRetention
{
    /** kind => [table, audience bridge, bridge FK] */
    public const KINDS = [
        'learning_content' => ['learning_content', 'learning_content_section_audiences', 'learning_content_id'],
        'assignment' => ['assignments', 'assignment_section_audiences', 'assignment_id'],
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly ReferencingRows $references,
        private readonly DocumentParentRetention $documents,
        private readonly RetentionExpiry $expiry,
    ) {}

    /** @return array{eligible: int, deleted: int, held: int, unresolved: int, dependency_blocked: int, errors: int} */
    public function prune(string $kind, School $school, string $cutoffDate, int $batch, bool $dryRun, bool $held): array
    {
        [$table, $bridge, $fk] = self::KINDS[$kind] ?? throw new InvalidArgumentException("Not an LMS resource kind: {$kind}");

        $category = $kind === 'learning_content' ? RetentionMetrics::LEARNING_CONTENT : RetentionMetrics::ASSIGNMENT;

        // A refused unit (no retention identity) carries no `held`.
        return $this->expiry->privileged($category, $dryRun || $held, fn (): array => $this->context->withSchool($school, function () use ($kind, $school, $table, $bridge, $fk, $cutoffDate, $batch, $dryRun, $held): array {
            $result = ['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0];

            $this->yearEnded(DB::table($table.' as r')->where('r.school_id', $school->id), $cutoffDate)->select('r.id')
                ->chunkById($batch, function ($resources) use (&$result, $kind, $school, $table, $bridge, $fk, $cutoffDate, $dryRun, $held): void {
                    foreach ($resources as $resource) {
                        RetentionUnit::purge(
                            $result,
                            $dryRun || $held,
                            // A plain read: the function locks the resource itself.
                            fn (): bool => $this->yearEnded(DB::table($table.' as r')->where('r.school_id', $school->id)->where('r.id', $resource->id), $cutoffDate)->first(['r.id']) !== null,
                            fn (): array => array_filter([$this->blocker($kind, $school->id, $resource->id, $table, $bridge, $fk)]),
                            fn (): ?array => $this->expire($kind, $school, $resource->id, $cutoffDate),
                        );
                    }
                }, 'r.id', 'id');

            $result['held'] = 0;
            if ($held) {
                $result['held'] = $result['eligible'];
                $result['dependency_blocked'] = 0;
            }

            return $result;
        }), $school->id) + ['held' => 0];
    }

    /**
     * E21-RH.5: the database unit, in a savepoint. The plain recheck takes no
     * lock, so the function may find, once it holds the resource lock, that a
     * concurrent purge already removed it (`retention_lms`) or that a hold was
     * placed meanwhile (`retention_hold`): the unit is kept, never an error.
     *
     * @return list<object{storage_disk: string, storage_path: string}>|null
     */
    private function expire(string $kind, School $school, string $id, string $cutoffDate): ?array
    {
        try {
            return DB::transaction(fn (): array => $this->expiry->lmsResource($kind, $school, $id, $cutoffDate));
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), '(retention_lms)') || str_contains($e->getMessage(), '('.RetentionExpiry::REFUSED_HELD.')')) {
                return null;
            }

            throw $e;
        }
    }

    /** Narrows `r` to resources whose Offering's Academic Year ended strictly before the cutoff. */
    private function yearEnded(Builder $query, string $cutoffDate): Builder
    {
        return $query->whereExists(fn (Builder $q) => AcademicYearRetention::endedBefore(
            $q->selectRaw('1')->from('subject_offerings as o')->whereColumn('o.id', 'r.subject_offering_id')->whereColumn('o.school_id', 'r.school_id'),
            'o',
            $cutoffDate,
        ));
    }

    private function blocker(string $kind, string $schoolId, string $id, string $table, string $bridge, string $fk): ?string
    {
        $blocker = $this->references->first($table, $schoolId, [$id], ['documents', $bridge])
            ?? $this->references->first('documents', $schoolId, $this->documents->idsOwnedBy($kind, $id));
        if ($blocker !== null) {
            return $blocker;
        }

        $resource = DB::table($table.' as r')->join('subject_offerings as o', fn ($j) => $j->on('o.id', '=', 'r.subject_offering_id')->on('o.school_id', '=', 'r.school_id'))
            ->join('academic_years as y', fn ($j) => $j->on('y.id', '=', 'o.academic_year_id')->on('y.school_id', '=', 'o.school_id'))
            ->where('r.id', $id)->first(['r.owner_employee_id', 'r.subject_offering_id', 'y.ends_on']);
        if ($resource === null || $resource->owner_employee_id === null) {
            return null; // Offering-wide administrative material carries no embedded authority.
        }

        // D6: when the owner's authority over this resource last applied.
        $assignments = DB::table('teaching_assignments as t')->join($bridge.' as a', fn ($j) => $j->on('a.section_id', '=', 't.section_id')->on('a.school_id', '=', 't.school_id'))
            ->where('a.'.$fk, $id)->where('t.employee_id', $resource->owner_employee_id)->where('t.subject_offering_id', $resource->subject_offering_id);
        if ((clone $assignments)->whereNull('t.ends_on')->select('t.section_id')->exists()) {
            return 'teaching_assignments';
        }
        $ended = max((string) $resource->ends_on, (string) ((clone $assignments)->max('t.ends_on') ?? $resource->ends_on));

        return EmbeddedAuthorityRetention::mayRemoveWithParent(CarbonImmutable::parse(substr($ended, 0, 10), 'UTC')->endOfDay())
            ? null
            : 'authority_history';
    }
}
