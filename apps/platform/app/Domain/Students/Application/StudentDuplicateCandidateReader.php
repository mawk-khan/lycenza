<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * SIS 2.3: what an operator may SEE of the duplicate candidates a refused
 * creation named (PossibleDuplicateStudentException::$candidateIds). Read
 * fresh, at render time, under the actor's own read authority -- a candidate
 * never shows more than the actor could already read:
 *
 * - **Whole-School surface** (Add Student, Admissions conversion): name,
 *   Student number, date of birth and status only with whole-School
 *   `students.view` (the Profile's floor); the current placement only within
 *   the actor's `enrollments.view` scope. Without `students.view` the
 *   warning shows a count and nothing else.
 * - **Campus surface** (Campus onboarding): only Students inside the actor's
 *   `students.view` Campus scope (the same rule that chose the candidates);
 *   never a date of birth (F2-D13); links to the Campus overview.
 *
 * No Guardian or contact data, no other School (RLS + explicit school_id).
 */
final class StudentDuplicateCandidateReader
{
    public function __construct(
        private readonly StudentAccess $access,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<string>  $candidateIds
     * @return array{candidateCount: int, hiddenCount: int, candidates: list<array{id: string, studentNumber: string, name: string, dateOfBirth: string|null, status: string, placement: string|null, href: string}>}
     */
    public function present(School $school, User $actor, array $candidateIds, bool $campusSurface = false): array
    {
        $viewScope = $this->access->scopeOf($actor, 'students.view', $school);
        if (! $campusSurface && ! $viewScope->isWholeSchool()) {
            return ['candidateCount' => count($candidateIds), 'hiddenCount' => count($candidateIds), 'candidates' => []];
        }

        return $this->context->withSchool($school, function () use ($school, $actor, $candidateIds, $campusSurface, $viewScope): array {
            $students = $this->access->students($school, $viewScope)->whereIn('students.id', $candidateIds)->orderBy('students.student_number')->get();
            $placements = $this->access->currentPlacements($school, $this->access->scopeOf($actor, 'enrollments.view', $school), $students->pluck('id')->map(fn ($id) => (string) $id)->all());

            $rows = $students->map(function (Student $s) use ($placements, $campusSurface): array {
                /** @var StudentEnrollment|null $placement */
                $placement = $placements->get($s->id);

                return [
                    'id' => (string) $s->id,
                    'studentNumber' => $s->student_number,
                    'name' => trim(implode(' ', array_filter([$s->first_name, $s->middle_name, $s->last_name]))),
                    'dateOfBirth' => $campusSurface ? null : $s->date_of_birth->toDateString(),
                    'status' => $s->status,
                    'placement' => $placement === null ? null
                        : "{$placement->gradeLevel->name} · Section {$placement->section->name} · {$placement->campus->name} · {$placement->academicYear->name}",
                    'href' => $campusSurface ? "/app/students/{$s->id}/overview" : "/app/students/{$s->id}",
                ];
            })->values()->all();

            // A Campus surface never counts what it cannot show (a candidate
            // that left the actor's scope since the refusal is not revealed).
            return $campusSurface
                ? ['candidateCount' => count($rows), 'hiddenCount' => 0, 'candidates' => $rows]
                : ['candidateCount' => count($candidateIds), 'hiddenCount' => count($candidateIds) - count($rows), 'candidates' => $rows];
        });
    }
}
