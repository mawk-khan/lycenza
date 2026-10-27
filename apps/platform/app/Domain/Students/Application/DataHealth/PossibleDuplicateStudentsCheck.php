<?php

namespace App\Domain\Students\Application\DataHealth;

use App\Domain\Students\Application\StudentDuplicateCandidates;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\DataHealth\DataHealthCheck;
use App\Support\DataHealth\DataHealthCheckDefinition as D;
use App\Support\DataHealth\DataHealthFinding;
use App\Support\DataHealth\DataHealthLink;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

/**
 * SIS 8.1: existing Students the SIS 2.3 duplicate-risk rule links --
 * same School, same date of birth AND the same first and last name or the
 * same full name ({@see StudentDuplicateCandidates::groupsIn()}, the ONE
 * rule creation and Admissions conversion also use). One finding per group,
 * so three matching records are one finding, never three pairs; the
 * finding total is the number of groups.
 *
 * A POSSIBLE duplicate, never proof: twins and namesakes are real. Inactive
 * Students are included (a leaver recreated instead of re-admitted is the
 * case that matters most). The date of birth is never shown (ADR 0075
 * §10.4): the row says only that it is the same. No merge, no repair.
 */
class PossibleDuplicateStudentsCheck implements DataHealthCheck
{
    public function __construct(
        private readonly StudentDuplicateCandidates $duplicates,
        private readonly StudentEnrollmentReadService $placements,
    ) {}

    public function definition(): D
    {
        return new D(
            code: 'student.possible_duplicate',
            area: 'Student records',
            title: 'Possible duplicate Student records',
            condition: 'Two or more Student records have the same date of birth and the same name (ignoring letter case, spaces and punctuation; a middle name may be missing on one of them). Inactive Students are included.',
            whyItMatters: 'If they are the same child, their history, attendance and fees are split across records, and a Student who left may have been added again instead of returning on their old record. They may also be different children who share a name and a birthday.',
            severity: D::REVIEW,
            kind: D::REVIEW_RECOMMENDED,
            viewCapabilities: ['students.view', 'enrollments.view'],
            repair: 'Open each record and compare them. If they are different children, nothing needs to change: the records stay listed here, because Data Health stores no decisions. If one was added by mistake, correct its details or mark it inactive on the Student page. Records are never merged or deleted automatically.',
        );
    }

    public function hasFindings(School $school): bool
    {
        return $this->duplicates->groupsIn($school) !== [];
    }

    public function findings(School $school, int $page, int $perPage): LengthAwarePaginator
    {
        $groups = $this->duplicates->groupsIn($school);
        $onPage = array_slice($groups, ($page - 1) * $perPage, $perPage);
        $current = $this->placements->currentForMany($school, array_map(
            fn (Student $s): string => (string) $s->id,
            array_merge(...$onPage ?: [[]]),
        ));

        $items = array_map(fn (array $group) => new DataHealthFinding(
            entityType: 'student_duplicate_group',
            entityId: (string) $group[0]->id,
            // The PresentsStudentFindings label shape: name and Student number only.
            label: implode(' · ', array_map(fn (Student $s): string => trim(implode(' ', array_filter([$s->first_name, $s->middle_name, $s->last_name])))." ({$s->student_number})", $group)),
            detail: count($group).' records with the same date of birth · '.implode('; ', array_map(function (Student $s) use ($current): string {
                $placement = $current->get($s->id);
                $class = $placement === null ? 'no class this year' : trim(implode(' · ', array_filter([$placement->gradeLevel?->name, $placement->section?->name])));

                return "{$s->student_number}: ".($s->status === 'active' ? 'Active' : 'Inactive').", {$class}";
            }, $group)),
            links: array_map(fn (Student $s) => new DataHealthLink("Open {$s->student_number}", "/app/students/{$s->id}", ['students.view']), $group),
        ), $onPage);

        return new Paginator($items, count($groups), $perPage, $page);
    }
}
