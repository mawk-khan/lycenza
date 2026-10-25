<?php

namespace App\Domain\Guardians\Application;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * POR (ADR 0070 §6, §25): the ONE answer to "which Students may this
 * Guardian persona reach through the portal, in this School, right now?" --
 * derived LIVE from `student_guardian_relationships` on every call. Never from
 * the account link, invitation history, names, contact data, membership, role,
 * or any cached Student id; never cached.
 *
 * The eligibility predicate is an ENGINEERING FAIL-CLOSED DEFAULT PENDING
 * LEGAL/PRIVACY DETERMINATION (POR-L1, ADR 0058 E46), not a legal
 * conclusion: a relationship recorded with `is_legal_guardian = true`, to a
 * Student whose status is `active`, by a Guardian whose status is `active`,
 * all in this School. POR-L1 may broaden, narrow or replace it (an ADR 0070
 * amendment). Withdrawn, transferred or inactive Students are out of scope:
 * no historical access until POR-L1 answers.
 *
 * eligibleStudentIdsQuery() is the single predicate source. Consumers put it
 * INSIDE their data query (`whereIn(student_id, …)`), so the read and the
 * authorization are one SQL statement under one snapshot.
 */
final class GuardianStudentScope
{
    public function __construct(private readonly TenantContext $context) {}

    public function isActiveGuardian(School $school, string $guardianId): bool
    {
        return $this->context->withSchool($school, fn (): bool => Guardian::query()
            ->where('school_id', $school->id)
            ->whereKey($guardianId)
            ->where('status', 'active')
            ->exists());
    }

    /**
     * The Student ids this Guardian may reach -- a subquery to embed in the
     * caller's own query. Run it under this School's TenantContext (RLS).
     */
    public function eligibleStudentIdsQuery(School $school, string $guardianId): Builder
    {
        return $this->eligibleRelationships($school)
            ->where('sgr.guardian_id', $guardianId)
            ->select('sgr.student_id');
    }

    /**
     * ORG.9D-2 (ADR 0076 §74): the same predicate the other way round --
     * for each given Student, the Guardians who may reach them right now
     * (a legal-guardian relationship, an active Student, an active
     * Guardian, all in this School). A Student with no eligible Guardian is
     * absent.
     *
     * Three bounded, single-table reads intersected here -- never the
     * three-way join, which runs as a nested-loop cross product on thin
     * statistics (ORG.9D-2 query review: ~60 s for 1,000 Students).
     * GuardianStudentScopeTest keeps it equal to eligibleStudentIdsQuery().
     *
     * @param  list<string>  $studentIds
     * @return array<string, list<string>> studentId => guardianIds
     */
    public function eligibleGuardiansByStudent(School $school, array $studentIds): array
    {
        $studentIds = array_values(array_unique($studentIds));
        if ($studentIds === []) {
            return [];
        }

        return $this->context->withSchool($school, function () use ($school, $studentIds): array {
            $active = [];
            foreach (array_chunk($studentIds, 5000) as $chunk) {
                $active += array_flip(DB::table('students')->where('school_id', $school->id)->where('status', 'active')
                    ->whereIn('id', $chunk)->pluck('id')->map(fn ($id) => (string) $id)->all());
            }
            if ($active === []) {
                return [];
            }

            $pairs = collect();
            foreach (array_chunk(array_map('strval', array_keys($active)), 5000) as $chunk) {
                $pairs = $pairs->concat(DB::table('student_guardian_relationships')->where('school_id', $school->id)->where('is_legal_guardian', true)
                    ->whereIn('student_id', $chunk)->get(['student_id', 'guardian_id']));
            }
            $guardians = [];
            foreach (array_chunk($pairs->pluck('guardian_id')->map(fn ($id) => (string) $id)->unique()->values()->all(), 5000) as $chunk) {
                $guardians += array_flip(DB::table('guardians')->where('school_id', $school->id)->where('status', 'active')
                    ->whereIn('id', $chunk)->pluck('id')->map(fn ($id) => (string) $id)->all());
            }

            $byStudent = [];
            foreach ($pairs as $pair) {
                if (isset($guardians[(string) $pair->guardian_id])) {
                    $byStudent[(string) $pair->student_id][] = (string) $pair->guardian_id;
                }
            }
            foreach ($byStudent as &$ids) {
                $ids = array_values(array_unique($ids));
                sort($ids);
            }

            return $byStudent;
        });
    }

    /** The one eligibility predicate (see the class docblock), un-narrowed. */
    private function eligibleRelationships(School $school): Builder
    {
        return DB::table('student_guardian_relationships as sgr')
            ->join('students as s', fn ($j) => $j->on('s.id', '=', 'sgr.student_id')->on('s.school_id', '=', 'sgr.school_id'))
            ->join('guardians as g', fn ($j) => $j->on('g.id', '=', 'sgr.guardian_id')->on('g.school_id', '=', 'sgr.school_id'))
            ->where('sgr.school_id', $school->id)
            ->where('sgr.is_legal_guardian', true)
            ->where('s.status', 'active')
            ->where('g.status', 'active');
    }

    public function hasEligibleStudent(School $school, string $guardianId): bool
    {
        return $this->context->withSchool($school, fn (): bool => $this->eligibleStudentIdsQuery($school, $guardianId)->exists());
    }

    public function holdsStudent(School $school, string $guardianId, string $studentId): bool
    {
        return $this->context->withSchool($school, fn (): bool => $this->eligibleStudentIdsQuery($school, $guardianId)
            ->where('sgr.student_id', $studentId)->exists());
    }

    /**
     * The Guardian's own eligible Students -- id and display name only; never
     * a School-wide list, never a sibling outside the Guardian's own scope.
     *
     * @return list<array{id: string, name: string}>
     */
    public function eligibleStudents(School $school, string $guardianId): array
    {
        return $this->context->withSchool($school, fn (): array => DB::table('students')
            ->where('school_id', $school->id)
            ->whereIn('id', $this->eligibleStudentIdsQuery($school, $guardianId))
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn ($s): array => ['id' => (string) $s->id, 'name' => trim($s->first_name.' '.($s->last_name ?? ''))])
            ->values()
            ->all());
    }
}
