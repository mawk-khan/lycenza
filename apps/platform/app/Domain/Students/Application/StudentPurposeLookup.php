<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\TenantContext;

/**
 * ORG.7D-2 (ADR 0076 §38, D2-7, D2-8): purpose-limited Student candidate
 * lookup -- APPLICATION INFRASTRUCTURE for a consuming domain that has
 * ALREADY authorized its own business capability, never a Student
 * authorization of its own and never a substitute for `students.view`.
 *
 *   domain capability check
 *     -> CampusScopeResolver for THAT domain capability
 *     -> the authorized CampusScope
 *     -> search(school, scope, term)
 *
 * The scope is a CampusScope value -- application code never builds one
 * outside CampusScopeResolver (CampusScopeAdoptionArchitectureTest) -- so a
 * request-supplied Campus id can never select the candidate set.
 *
 * - Candidates: current operational Students only
 *   (StudentOperationalCampusResolver::restrictToOperationalCampus()) -- an
 *   active Student, current placement in the active year, active Campus;
 *   a Campus-limited scope keeps only those whose OPERATIONAL Campus is in
 *   it (history or a future placement never counts). No scope, or no active
 *   year: nothing.
 * - Filtered entirely in SQL, one bounded query; each Student at most once.
 * - Search: name words and Student number only (StudentSearch::anyField(),
 *   LIKE input escaped).
 * - Result: StudentLookupCandidate (id, displayName, studentNumber) only,
 *   deterministically ordered.
 *
 * No route exposes it, and no module outside Students calls it yet: a
 * consuming domain's own, separately authorized endpoint adopts it later
 * (StudentCampusFoundationArchitectureTest pins that).
 */
final class StudentPurposeLookup
{
    public const int DEFAULT_LIMIT = 20;

    public const int MAX_LIMIT = 50;

    public function __construct(
        private readonly StudentOperationalCampusResolver $operational,
        private readonly TenantContext $context,
    ) {}

    /** @return list<StudentLookupCandidate> */
    public function search(School $school, CampusScope $scope, ?string $term, int $limit = self::DEFAULT_LIMIT): array
    {
        if ($scope->isNone()) {
            return [];
        }
        $limit = max(1, min($limit, self::MAX_LIMIT));

        return $this->context->withSchool($school, fn (): array => $this->operational
            ->restrictToOperationalCampus(Student::query()->where('students.school_id', $school->id), $school, $scope)
            ->when(trim((string) $term) !== '', fn ($q) => StudentSearch::anyField($q, (string) $term))
            ->orderBy('students.first_name')->orderBy('students.last_name')->orderBy('students.student_number')->orderBy('students.id')
            ->limit($limit)
            ->get(['students.id', 'students.first_name', 'students.middle_name', 'students.last_name', 'students.student_number'])
            ->map(fn (Student $s): StudentLookupCandidate => new StudentLookupCandidate(
                (string) $s->id,
                trim(implode(' ', array_filter([$s->first_name, $s->middle_name, $s->last_name], fn (?string $p): bool => $p !== null && $p !== ''))),
                $s->student_number,
            ))
            ->values()->all());
    }
}
