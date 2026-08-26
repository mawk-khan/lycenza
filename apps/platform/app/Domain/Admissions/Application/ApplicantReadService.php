<?php

namespace App\Domain\Admissions\Application;

use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Phase 1D.4: the canonical read layer for Applicant identity --
 * mirrors App\Domain\Students\Application\StudentEnrollmentReadService's
 * exact shape (plain Eloquent models/paginators, no DTOs/Resources,
 * authorization-neutral, relies entirely on the ambient SchoolScope/
 * RLS already active on `applicants`/`admission_applications`). A
 * future controller must call `Gate::authorize`/`authorizeCapability`
 * with `admissions.view` before ever reaching this class -- no
 * capability check lives here (root CLAUDE.md rule 24, this
 * checkpoint's own item 32).
 *
 * `search()` deliberately selects only `id`/`school_id`/`first_name`/
 * `middle_name`/`last_name` -- never `date_of_birth` -- matching
 * `StudentController::index()`/`presentSummary()`'s established
 * "DOB excluded from list/search, included only at detail level"
 * privacy projection (`docs/modules/STUDENT-GUARDIAN-IDENTITY.md`).
 * `detail()` returns the full model (DOB included), matching that same
 * precedent's detail-level behavior.
 *
 * Never mutates state. Never audited -- reads are reads, matching
 * every other read service in this codebase.
 */
class ApplicantReadService
{
    public const int MAX_PER_PAGE = 100;

    public const int DEFAULT_PER_PAGE = 25;

    /**
     * Name-only search (first_name/last_name, `ilike '%term%'` --
     * exactly `StudentController::index()`'s own name-search shape, no
     * fuzzy/phonetic matching, no DOB/decision_note involvement). A
     * blank/omitted `$name` returns every Applicant in the current
     * School.
     */
    public function search(?string $name = null, int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $query = Applicant::query()
            ->select(['id', 'school_id', 'first_name', 'middle_name', 'last_name'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id');

        if ($name !== null && $name !== '') {
            $term = '%'.$name.'%';
            $query->where(fn ($q) => $q->where('first_name', 'ilike', $term)->orWhere('last_name', 'ilike', $term));
        }

        return $query->paginate($perPage);
    }

    /**
     * A single Applicant, full identity fields (DOB included -- detail-
     * level, not list-level). `find()` relies entirely on the ambient
     * SchoolScope/RLS already active on `applicants` -- a foreign-
     * School id and a random UUID both simply resolve to `null`,
     * exactly like `StudentEnrollmentReadService::detail()`'s identical
     * non-enumeration behavior; this class never distinguishes
     * "belongs to another School" from "does not exist".
     */
    public function detail(string $applicantId): ?Applicant
    {
        return Applicant::query()->find($applicantId);
    }

    /**
     * Every AdmissionApplication this Applicant has ever had, across
     * every status, newest first -- the full reapplication history,
     * never collapsed to "the current one" (`docs/modules/ADMISSIONS.md`
     * §3: a rejected/withdrawn application is never mutated or replaced,
     * reapplication is always a new row). Eager-loads only the academic
     * context (`academicYear`/`campus`/`gradeLevel`) -- never Guardian/
     * contact/converted-Student broader relations; a future detail view
     * eager-loading conversion provenance calls
     * `AdmissionApplicationReadService::detail()` for that one row
     * instead.
     *
     * @return Collection<int, AdmissionApplication>
     */
    public function applications(Applicant $applicant): Collection
    {
        return AdmissionApplication::query()
            ->with(['academicYear', 'campus', 'gradeLevel'])
            ->where('applicant_id', $applicant->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }
}
