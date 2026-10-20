<?php

namespace App\Domain\Students\Http\Controllers;

use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Application\StudentSearch;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Models\School;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ORG.7F-1 (ADR 0076 §45): the Campus-aware Student list of the JSON API.
 * Whole School: every Student of the School (unchanged shape). Campus set:
 * only Students whose CURRENT operational Campus is in scope (StudentAccess,
 * SQL), each row the safe operational identity only. Never date of birth:
 * the detail (`students.show`) is a declared whole-School-only operation.
 */
class StudentDirectoryController extends Controller
{
    use AuthorizesCampusScope;

    public function index(Request $request, School $school, StudentAccess $access): JsonResponse
    {
        $scope = $this->authorizeCampusScope('students.view', $school);

        $validated = $request->validate([
            'student_number' => ['sometimes', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $access->students($school, $scope)->orderBy('first_name')->orderBy('last_name')->orderBy('students.id');

        if (isset($validated['student_number'])) {
            $query->where('students.student_number', $validated['student_number']);
        }
        // ORG.7F-1: every name word in any name field, `%`/`_`/`\` literal
        // (StudentSearch) -- user input never becomes a SQL wildcard.
        if (isset($validated['name'])) {
            StudentSearch::name($query, $validated['name']);
        }
        // Narrowing only (a Campus actor's list holds active Students alone).
        if (isset($validated['status'])) {
            $query->where('students.status', $validated['status']);
        }

        $paginator = $query->paginate($validated['per_page'] ?? 25);
        $campusView = ! $scope->isWholeSchool();

        return response()->json([
            'data' => collect($paginator->items())->map(fn (Student $s) => [
                'id' => $s->id,
                'studentNumber' => $s->student_number,
                'firstName' => $s->first_name,
                'middleName' => $s->middle_name,
                'lastName' => $s->last_name,
                ...($campusView ? [] : ['status' => $s->status]),
            ])->all(),
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
