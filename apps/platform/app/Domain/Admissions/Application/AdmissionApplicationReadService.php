<?php

namespace App\Domain\Admissions\Application;

use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Phase 1D.4: the canonical read layer for AdmissionApplication --
 * named per `docs/admissions/PHASE-1D-0-ADMISSIONS-ARCHITECTURE.md`
 * §7's own explicit "1D.4 -- AdmissionApplicationReadService for list/
 * filter" naming. Mirrors
 * App\Domain\Students\Application\StudentEnrollmentReadService and
 * App\Domain\Students\Application\EnrollmentRolloverReadService's
 * exact shape: plain array `$filters`, `LengthAwarePaginator` return,
 * no DTOs/Resources, authorization-neutral (no `User`/`Gate` here -- a
 * future controller calls `authorizeCapability('admissions.view', ...)`
 * before ever reaching this class, root CLAUDE.md rule 24/this
 * checkpoint's item 32). Relies entirely on the ambient SchoolScope/
 * RLS already active on `admission_applications` -- never calls
 * `TenantContext::withSchool()` itself, matching both precedents'
 * identical "caller is always an already-tenant-resolved request/job"
 * assumption.
 *
 * Never mutates state. Never audited -- reads are reads.
 */
class AdmissionApplicationReadService
{
    public const int MAX_PER_PAGE = 100;

    public const int DEFAULT_PER_PAGE = 25;

    /**
     * `ADMISSIONS.md` §6's real lifecycle vocabulary -- the ONLY
     * allow-listed values a `status` filter may match. An unrecognized
     * value is silently ignored (the filter is simply not applied)
     * rather than passed through to the query, matching this
     * checkpoint's item 12: "the read surface should still use known
     * application states" even though the database itself has no
     * status CHECK (`ADMISSIONS.md` §6A).
     */
    private const array KNOWN_STATUSES = ['draft', 'submitted', 'accepted', 'rejected', 'withdrawn', 'converted'];

    /**
     * Paginated, filtered AdmissionApplication listing for the current
     * School. Every filter is applied to already tenant-scoped query
     * state (`AdmissionApplication`'s own SchoolScope/RLS, and
     * `applicant` via a `whereHas` on the SAME scoped relation) -- a
     * foreign-School id passed in any filter simply matches zero rows,
     * it can never widen or redirect the query outside the current
     * School, exactly matching
     * `StudentEnrollmentReadService::directory()`'s identical proven
     * behavior (this checkpoint's own
     * `a_foreign_school_filter_id_matches_zero_rows_never_widens_the_query`
     * test).
     *
     * Deliberately selects a column list EXCLUDING `decision_note` --
     * internal Admissions information that should not be hydrated for
     * a bulk list view unless actually required (item 19); `detail()`
     * below returns the full model, `decision_note` included. Eager-
     * loads `applicant` with a PARTIAL column selection excluding
     * `date_of_birth` (matching `ApplicantReadService::search()`'s
     * identical list-level DOB exclusion, itself matching
     * `StudentController`'s established precedent) plus `academicYear`/
     * `campus`/`gradeLevel` -- never Guardian/contact/converted-Student
     * relations, which stay `detail()`-only.
     *
     * @param  array{status?: string, academic_year_id?: string, campus_id?: string, grade_level_id?: string, applicant_name?: string}  $filters
     */
    public function directory(array $filters = [], int $perPage = self::DEFAULT_PER_PAGE): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        $query = AdmissionApplication::query()
            ->select([
                'id', 'school_id', 'applicant_id', 'academic_year_id', 'campus_id', 'grade_level_id',
                'status', 'converted_student_id', 'converted_student_enrollment_id', 'converted_at',
                'created_at', 'updated_at',
            ])
            ->with([
                'applicant:id,school_id,first_name,middle_name,last_name',
                'academicYear', 'campus', 'gradeLevel',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if (isset($filters['status']) && in_array($filters['status'], self::KNOWN_STATUSES, true)) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['academic_year_id'])) {
            $query->where('academic_year_id', $filters['academic_year_id']);
        }
        if (isset($filters['campus_id'])) {
            $query->where('campus_id', $filters['campus_id']);
        }
        if (isset($filters['grade_level_id'])) {
            $query->where('grade_level_id', $filters['grade_level_id']);
        }
        if (isset($filters['applicant_name']) && $filters['applicant_name'] !== '') {
            $term = '%'.$filters['applicant_name'].'%';
            $query->whereHas('applicant', fn ($q) => $q->where('first_name', 'ilike', $term)->orWhere('last_name', 'ilike', $term));
        }

        return $query->paginate($perPage);
    }

    /**
     * A single AdmissionApplication with every relationship needed to
     * explain it -- Applicant (full, DOB included -- detail-level),
     * academic context, AND conversion provenance
     * (`convertedStudent`/`convertedStudentEnrollment`, both nullable
     * -- a converted application's historical Student/Enrollment
     * references remain readable regardless of that Student's CURRENT
     * status, `ADMISSIONS.md` §7/§8; this class never re-derives or
     * rewrites historical application state from current Student data).
     * `decision_note` is included -- internal detail is appropriate
     * here, unlike the list view above (item 19).
     *
     * `find()` relies entirely on the ambient SchoolScope/RLS already
     * active on `admission_applications` -- a foreign-School id and a
     * random UUID both simply resolve to `null`, never distinguished
     * (this checkpoint's item 22, proven directly by Phase 1D.2/1D.3's
     * own leakage tests and re-proven here).
     */
    public function detail(string $applicationId): ?AdmissionApplication
    {
        return AdmissionApplication::query()
            ->with(['applicant', 'academicYear', 'campus', 'gradeLevel', 'convertedStudent', 'convertedStudentEnrollment'])
            ->find($applicationId);
    }
}
