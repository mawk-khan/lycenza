<?php

namespace App\Domain\Students\Application;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Authorization\Campus\CampusScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * SIS 11.4: the ONE Student Directory query, shared by the Directory page and
 * its governed export (so the two can never drift). Every Student is read
 * through StudentAccess under the viewer's `students.view` scope; the
 * current-placement filters under their `enrollments.view` scope (ORG.7F-1,
 * SIS.EXP-1). Filters only narrow, in SQL; the order is the closed
 * StudentDirectoryOrder.
 */
final class StudentDirectoryQuery
{
    public function __construct(private readonly StudentAccess $access) {}

    /** @return array<string, mixed> the Directory's request contract */
    public static function rules(): array
    {
        return [
            'student_number' => ['sometimes', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            // SIS.EXP-1: current-placement filters (they only narrow, in SQL).
            'campus_id' => ['sometimes', 'nullable', 'uuid'],
            'grade_level_id' => ['sometimes', 'nullable', 'uuid'],
            'section_id' => ['sometimes', 'nullable', 'uuid'],
            // SIS 11.1: a closed sort (StudentDirectoryOrder, governed by the SIS report catalogue).
            'sort' => ['sometimes', 'nullable', Rule::in(array_keys(StudentDirectoryOrder::SORTS))],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters  validated by rules()
     * @return Builder<Student>
     */
    public function build(School $school, CampusScope $scope, CampusScope $placementScope, array $filters): Builder
    {
        $query = StudentDirectoryOrder::apply($this->access->students($school, $scope), $filters['sort'] ?? null);

        // UAT-C4 (UAT-SRCH-01): any part of the number; every name word in
        // any name field (StudentSearch, LIKE-escaped).
        if (isset($filters['student_number'])) {
            StudentSearch::number($query, $filters['student_number']);
        }
        if (isset($filters['name'])) {
            StudentSearch::name($query, $filters['name']);
        }
        // Narrowing only: a Campus actor's Directory holds active Students
        // alone, so asking for inactive ones finds nothing.
        if (isset($filters['status'])) {
            $query->where('students.status', $filters['status']);
        }

        return $this->access->narrowToCurrentPlacement($query, $school, $placementScope, $filters);
    }
}
