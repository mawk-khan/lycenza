<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Infrastructure\Student;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 5B.1 §25/§26: the Student/Guardian search endpoints for the
 * Announcement composer's audience picker -- mirrors
 * App\Domain\Communications\Http\Controllers\CommunicationHubController::searchParticipants()'s
 * exact shape (same-School, `ilike` name search, capped, minimal safe
 * fields only).
 *
 * Deliberately gated by `communications.announce` -- NOT
 * `students.view`/`guardians.view` (root CLAUDE.md rule 24: a
 * capability check, never inferred from an unrelated module's
 * capability). An announcer who cannot view the Student/Guardian admin
 * module can still target one by name here; this endpoint returns only
 * what a communication composer is authorized to see (name/student
 * number identity), never a confidential field
 * (date_of_birth/GuardianContact values/etc, brief §25/§26).
 */
class CommunicationAudienceSearchController extends Controller
{
    use AuthorizesCapability;

    public function students(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);

        $q = trim((string) $request->string('q'));

        $students = Student::query()
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->when($q !== '', fn ($query) => $query
                ->where(fn ($query) => $query
                    ->whereRaw("(first_name || ' ' || coalesce(last_name, '')) ilike ?", ["%{$q}%"])
                    ->orWhere('student_number', 'ilike', "%{$q}%")))
            ->orderBy('first_name')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'student_number']);

        return response()->json([
            'students' => $students->map(fn (Student $s) => [
                'id' => $s->id,
                'label' => trim("{$s->first_name} {$s->last_name}")." ({$s->student_number})",
            ])->values(),
        ]);
    }

    public function guardians(Request $request, TenantContext $context): JsonResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.announce', $school);

        $q = trim((string) $request->string('q'));

        $guardians = Guardian::query()
            ->where('school_id', $school->id)
            ->where('status', 'active')
            ->when($q !== '', fn ($query) => $query
                ->whereRaw("(first_name || ' ' || coalesce(last_name, '')) ilike ?", ["%{$q}%"]))
            ->orderBy('first_name')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name']);

        return response()->json([
            'guardians' => $guardians->map(fn (Guardian $g) => [
                'id' => $g->id,
                'label' => trim("{$g->first_name} {$g->last_name}"),
            ])->values(),
        ]);
    }
}
