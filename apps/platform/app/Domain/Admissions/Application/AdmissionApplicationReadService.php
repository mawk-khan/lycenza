<?php

namespace App\Domain\Admissions\Application;

use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Admissions\Infrastructure\Applicant;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Search\NameSearch;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Phase 1D.4: the canonical read layer for AdmissionApplication --
 * named per `docs/admissions/PHASE-1D-0-ADMISSIONS-ARCHITECTURE.md`
 * §7's own explicit "1D.4 -- AdmissionApplicationReadService for list/
 * filter" naming. Mirrors
 * App\Domain\Students\Application\StudentEnrollmentReadService and
 * App\Domain\Students\Application\EnrollmentRolloverReadService's
 * exact shape: plain array `$filters`, `LengthAwarePaginator` return,
 * no DTOs/Resources, authorization-neutral (no `User`/`Gate` here -- a
 * controller authorizes `admissions.view` (ORG.7D-3: through its Campus
 * scope, AdmissionApplicationAccess) before ever reaching this class, root CLAUDE.md rule 24/this
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
     * SIS 11.1/11.2: the closed sort contract of the application list
     * (governed by the SIS report catalogue, StudentReportRegistry). Request
     * input only ever picks a key (the first is the default); each ends in
     * `id` so pagination is stable.
     */
    /** SIS 11.4: the list's request contract, shared by the page and its governed export. */
    public static function listRules(): array
    {
        return [
            'status' => ['sometimes', Rule::in(self::KNOWN_STATUSES)],
            'academic_year_id' => ['sometimes', 'uuid'],
            'campus_id' => ['sometimes', 'uuid'],
            'grade_level_id' => ['sometimes', 'uuid'],
            'applicant_name' => ['sometimes', 'string', 'max:255'],
            'created_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'created_to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:created_from'],
            'sort' => ['sometimes', 'nullable', Rule::in(array_keys(self::SORTS))],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public const array SORTS = [
        'created_desc' => 'Newest first',
        'created_asc' => 'Oldest first',
        'applicant' => 'Applicant name',
        'status' => 'Status',
    ];

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
     * ORG.7D-3 (ADR 0076 §39): a $scope restricts the list, in SQL, to the
     * applications of the actor's Campuses; a request `campus_id` filter then
     * only narrows. Null keeps the whole-School list unchanged.
     *
     * @param  array{status?: string, academic_year_id?: string, campus_id?: string, grade_level_id?: string, applicant_name?: string, created_from?: string, created_to?: string, sort?: string}  $filters
     * @param  ?\DateTimeZone  $timezone  the School's zone for the created-date bounds (UTC when omitted)
     */
    public function directory(array $filters = [], int $perPage = self::DEFAULT_PER_PAGE, ?CampusScope $scope = null, ?\DateTimeZone $timezone = null): LengthAwarePaginator
    {
        return $this->directoryQuery($filters, $scope, $timezone)->paginate(max(1, min($perPage, self::MAX_PER_PAGE)));
    }

    /**
     * SIS 11.4: the ONE application-list query (filters, scope, sort), shared
     * by the list page, its API and the governed export.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<AdmissionApplication>
     */
    public function directoryQuery(array $filters = [], ?CampusScope $scope = null, ?\DateTimeZone $timezone = null): Builder
    {
        $query = AdmissionApplication::query()
            ->select([
                'id', 'school_id', 'applicant_id', 'academic_year_id', 'campus_id', 'grade_level_id',
                'status', 'converted_student_id', 'converted_student_enrollment_id', 'converted_at',
                'created_at', 'updated_at',
            ])
            ->with([
                'applicant:id,school_id,first_name,middle_name,last_name',
                'academicYear', 'campus', 'gradeLevel',
            ]);
        $this->order($query, (string) ($filters['sort'] ?? 'created_desc'));
        $scope?->restrict($query);

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
            // SIS 9.1: the shared person-name rule (every word in any name
            // field, any order); LIKE wildcards stay literal (ORG.7D-3 R8).
            $query->whereHas('applicant', fn ($q) => NameSearch::everyWord($q, $filters['applicant_name'], ['applicants.first_name', 'applicants.middle_name', 'applicants.last_name']));
        }
        // SIS 11.2: the School-local calendar day the application was created
        // (created_at is stored in UTC; the bounds are that day's UTC instants,
        // so the column's index still serves the range).
        $zone = $timezone ?? new \DateTimeZone('UTC');
        if (isset($filters['created_from']) && $filters['created_from'] !== '') {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['created_from'], $zone)->startOfDay()->utc()->toDateTimeString());
        }
        if (isset($filters['created_to']) && $filters['created_to'] !== '') {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['created_to'], $zone)->addDay()->startOfDay()->utc()->toDateTimeString());
        }

        return $query;
    }

    /**
     * @param  Builder<AdmissionApplication>  $query
     */
    private function order(Builder $query, string $sort): void
    {
        match (array_key_exists($sort, self::SORTS) ? $sort : 'created_desc') {
            'created_asc' => $query->orderBy('created_at')->orderBy('id'),
            // The Applicant's name, read by a correlated sub-select on the same School.
            'applicant' => $query->orderBy(Applicant::query()->select('last_name')->whereColumn('applicants.id', 'admission_applications.applicant_id'))
                ->orderBy(Applicant::query()->select('first_name')->whereColumn('applicants.id', 'admission_applications.applicant_id'))
                ->orderBy('id'),
            'status' => $query->orderBy('status')->orderByDesc('created_at')->orderByDesc('id'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
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
    public function detail(string $applicationId, ?CampusScope $scope = null): ?AdmissionApplication
    {
        $query = AdmissionApplication::query()
            ->with(['applicant', 'academicYear', 'campus', 'gradeLevel', 'convertedStudent', 'convertedStudentEnrollment']);

        // ORG.7D-3: outside the actor's Campus scope resolves to null (404).
        return ($scope === null ? $query : $scope->restrict($query))->find($applicationId);
    }
}
