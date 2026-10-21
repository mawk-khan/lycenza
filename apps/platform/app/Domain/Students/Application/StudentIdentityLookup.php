<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * ORG.7G-1 (ADR 0076 §50): the Students-owned IDENTITY projection for a
 * Student set another module has already decided (e.g. Finance's debtors:
 * Students with an OPEN Charge in the reader's scope). It never decides who
 * is eligible and never reads a Campus or a placement: every lifecycle
 * status is included (a leaver with debt stays findable), and the caller's
 * own SQL eligibility subquery bounds every row.
 *
 * Result: StudentLookupCandidate (id, displayName, studentNumber) only --
 * never date of birth, Guardians, contacts, documents, history or a Campus.
 * Authorization-neutral (the caller authorizes); not the Campus-scoped
 * StudentPurposeLookup (current operational Students), which answers a
 * different question.
 */
class StudentIdentityLookup
{
    public const int DEFAULT_LIMIT = 20;

    public const int MAX_LIMIT = 50;

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Students among `$eligibleStudentIds` (a subquery selecting student ids)
     * matching `$term` by name or number (escaped), bounded.
     *
     * @return list<StudentLookupCandidate>
     */
    public function searchAmong(School $school, QueryBuilder $eligibleStudentIds, ?string $term, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        return $this->context->withSchool($school, fn (): array => Student::query()
            ->where('students.school_id', $school->id)
            ->whereIn('students.id', $eligibleStudentIds)
            ->when(trim((string) $term) !== '', fn ($q) => StudentSearch::anyField($q, (string) $term))
            ->orderBy('students.first_name')->orderBy('students.last_name')->orderBy('students.student_number')->orderBy('students.id')
            ->limit($limit)
            ->get(['students.id', 'students.first_name', 'students.middle_name', 'students.last_name', 'students.student_number'])
            ->map(fn (Student $s): StudentLookupCandidate => self::candidate($s))
            ->values()->all());
    }

    /**
     * Any of the School's Students matching `$term`, bounded -- for a caller
     * already holding WHOLE-SCHOOL authority over Students (e.g. a
     * whole-School payment recorder).
     *
     * @return list<StudentLookupCandidate>
     */
    public function search(School $school, string $term, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));

        return $this->context->withSchool($school, fn (): array => Student::query()
            ->where('students.school_id', $school->id)
            ->tap(fn ($q) => StudentSearch::anyField($q, $term))
            ->orderBy('students.first_name')->orderBy('students.last_name')->orderBy('students.student_number')->orderBy('students.id')
            ->limit($limit)
            ->get(['students.id', 'students.first_name', 'students.middle_name', 'students.last_name', 'students.student_number'])
            ->map(fn (Student $s): StudentLookupCandidate => self::candidate($s))
            ->values()->all());
    }

    /**
     * Identity labels for Students a caller already holds rows for.
     *
     * @param  list<string>  $studentIds
     * @return array<string, StudentLookupCandidate> id => candidate
     */
    public function labels(School $school, array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        return $this->context->withSchool($school, fn (): array => Student::query()
            ->where('students.school_id', $school->id)
            ->whereIn('students.id', array_values(array_unique($studentIds)))
            ->get(['students.id', 'students.first_name', 'students.middle_name', 'students.last_name', 'students.student_number'])
            ->mapWithKeys(fn (Student $s): array => [(string) $s->id => self::candidate($s)])
            ->all());
    }

    /**
     * Ids of the School's Students matching `$term` (a narrowing filter for a
     * caller's own already-authorized rows), or of a lifecycle status.
     *
     * @param  list<string>|null  $within  only among these ids
     * @return list<string>
     */
    public function idsMatching(School $school, ?string $term = null, ?string $status = null, ?array $within = null): array
    {
        return $this->context->withSchool($school, fn (): array => Student::query()
            ->where('students.school_id', $school->id)
            ->when($within !== null, fn ($q) => $q->whereIn('students.id', $within))
            ->when(trim((string) $term) !== '', fn ($q) => StudentSearch::anyField($q, (string) $term))
            ->when($status !== null, fn ($q) => $q->where('students.status', $status))
            ->pluck('students.id')->map(fn ($id) => (string) $id)->values()->all());
    }

    private static function candidate(Student $s): StudentLookupCandidate
    {
        return new StudentLookupCandidate(
            (string) $s->id,
            trim(implode(' ', array_filter([$s->first_name, $s->middle_name, $s->last_name], fn (?string $p): bool => $p !== null && $p !== ''))),
            $s->student_number,
        );
    }
}
