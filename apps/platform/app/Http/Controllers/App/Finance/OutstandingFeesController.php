<?php

namespace App\Http\Controllers\App\Finance;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Fees\Application\ChargeCampusFilter;
use App\Domain\Payments\Application\Access\FinanceCampusAccess;
use App\Domain\Payments\Application\Charges\ChargeHistoryExpiredException;
use App\Domain\Payments\Application\Collections\OutstandingFeeRow;
use App\Domain\Payments\Application\Collections\OutstandingFeesReadService;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Domain\Students\Application\StudentIdentityLookup;
use App\Http\Controllers\Controller;
use App\Models\Campus;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * UAT-C3 (UAT-FIN-08): Finance -> Outstanding fees, the follow-up list of
 * Students who still owe money. Balances come only from Payments'
 * `OutstandingFeesReadService`, read through FinanceCampusAccess.
 *
 * ORG.7G-1 (ADR 0076 §50): Campus-aware (C6). Authority is the actor's
 * CampusScope for BOTH finance.charges.view and finance.payments.view
 * (their intersection), applied in SQL to each Charge's STORED Campus --
 * never the Student's current Campus, so a leaver's or a moved Student's
 * old debt stays with the Campus it was charged on, and NULL-Campus
 * (whole-School) Charges are never in a Campus view. Every request filter
 * (Student, standing, year, class, Campus) only narrows it. Names come from
 * Students' identity projection; class is shown and filterable only for a
 * whole-School enrollments.view holder, and means "in this class NOW" (the
 * current placement) -- it never re-attributes a Charge's Campus.
 * Staff only; no export, no headcount analytics.
 *
 * ORG.9B (ADR 0076 §68): billed / concession / paid / balance per Student
 * with FILTERED totals (every listed row, never one page); status `all`
 * also lists settled Students; `as_at` (a School-local date, never after
 * the School's today, never before the retained history) shows the
 * position at the end of that day. Same authority and Campus rule as above.
 */
class OutstandingFeesController extends Controller
{
    use AuthorizesCampusScope;

    private const int PER_PAGE = 25;

    /** LC-4 (SB-03b), ORG.7G-1A: the Campus filter's value for whole-School (unattributed, NULL-Campus) charges. */
    public const string CAMPUS_UNAVAILABLE = 'unavailable';

    public function index(Request $request, TenantContext $context, StudentAccess $students, FinanceCampusAccess $access, StudentIdentityLookup $identities, StudentEnrollmentReadService $placements): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope('finance.charges.view', $school)->intersect($this->authorizeCampusScope('finance.payments.view', $school));
        // ORG.7F-1: a School-wide Finance list shows classes only to a whole-School enrollments.view holder.
        $canSeeClass = $students->holdsWholeSchool($context->actor(), 'enrollments.view', $school);

        $validated = $request->validate([
            'status' => ['sometimes', Rule::in([OutstandingFeesReadService::OUTSTANDING, OutstandingFeesReadService::OVERDUE, OutstandingFeesReadService::ALL])],
            'as_at' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.SchoolTimezone::today($school)],
            'academic_year_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('academic_years', 'id')->where('school_id', $school->id)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'grade_level_id' => ['sometimes', 'nullable', 'uuid'],
            'section_id' => ['sometimes', 'nullable', 'uuid'],
            // LC-4 (SB-03b): `students.status` is the lifecycle -- a leaver is a
            // Student marked inactive, never one merely without a current class.
            'standing' => ['sometimes', 'nullable', Rule::in(['current', 'leavers'])],
            'campus_id' => ['sometimes', 'nullable', Rule::in([self::CAMPUS_UNAVAILABLE, ...Campus::query()->pluck('id')->map(fn ($id) => (string) $id)->all()])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $standing = $validated['standing'] ?? null;
        $campusId = $validated['campus_id'] ?? null;
        $status = $validated['status'] ?? OutstandingFeesReadService::OUTSTANDING;
        $search = trim((string) ($validated['q'] ?? ''));
        $gradeId = $canSeeClass ? ($validated['grade_level_id'] ?? null) : null;
        $sectionId = $canSeeClass ? ($validated['section_id'] ?? null) : null;

        $studentIds = $search === '' ? null : $identities->idsMatching($school, $search);
        // The Section filter's options are the active year's Sections.
        $activeYear = AcademicYear::query()->where('status', 'active')->first();
        // ORG.7D-2 (ADR 0076 §38): "in this class now" is the Students-owned
        // current placement (unchanged rule; no active year = no filter, as
        // before). Charge Campus stays charge-year attribution below.
        $inClass = $gradeId !== null || $sectionId !== null ? $placements->currentStudentIdsIn($school, $gradeId, $sectionId) : null;
        if ($inClass !== null) {
            $studentIds = $studentIds === null ? $inClass : array_values(array_intersect($studentIds, $inClass));
        }

        if ($standing !== null) {
            $inStanding = $identities->idsMatching($school, status: $standing === 'leavers' ? 'inactive' : 'active');
            $studentIds = $studentIds === null ? $inStanding : array_values(array_intersect($studentIds, $inStanding));
        }

        // ORG.7G-1A (ADR 0076 §49): the Campus filter narrows by each charge's
        // STORED historical Campus, in SQL -- written once when the charge was
        // assessed, never re-derived and never the Student's current Campus.
        // A filter, never authority; 'unavailable' = the whole-School charges.
        $chargeCampus = match ($campusId) {
            null => null,
            self::CAMPUS_UNAVAILABLE => ChargeCampusFilter::unattributed(),
            default => ChargeCampusFilter::campus($campusId),
        };

        $asAt = $validated['as_at'] ?? null;
        try {
            $list = $access->outstandingFees($school, $context->actor(), $status, $validated['academic_year_id'] ?? null, $studentIds, $chargeCampus, $asAt);
        } catch (ChargeHistoryExpiredException $e) {
            throw ValidationException::withMessages(['as_at' => "Figures before {$e->retainedFrom} are no longer retained; choose that date or a later one."]);
        }

        $page = (int) ($validated['page'] ?? 1);
        $slice = array_slice($list->rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);
        $ids = array_map(fn (OutstandingFeeRow $r) => $r->studentId, $slice);
        $names = $identities->labels($school, $ids);
        $leavers = array_flip($ids === [] ? [] : $identities->idsMatching($school, status: 'inactive', within: $ids));
        $classes = $canSeeClass ? $placements->currentForMany($school, $ids) : collect();

        $paginator = (new LengthAwarePaginator(
            array_map(fn (OutstandingFeeRow $r) => [
                'studentId' => $r->studentId,
                'name' => $names[$r->studentId]->displayName ?? '',
                'studentNumber' => $names[$r->studentId]->studentNumber ?? null,
                'leaver' => isset($leavers[$r->studentId]),
                'class' => ($e = $classes->get($r->studentId)) === null ? null : "{$e->gradeLevel?->name} · {$e->section?->name}",
                'billed' => $r->billed->amount(),
                'concession' => $r->concession->amount(),
                'paid' => $r->paid->amount(),
                'outstanding' => $r->outstanding->amount(),
                'overdue' => $r->overdue->amount(),
                'openCharges' => $r->openCharges,
                'earliestOverdueDueDate' => $r->earliestOverdueDueDate,
                'nextDueDate' => $r->nextDueDate,
            ], $slice),
            count($list->rows),
            self::PER_PAGE,
            $page,
            ['path' => '/app/finance/outstanding'],
        ))->withQueryString();

        return Inertia::render('App/Finance/Outstanding/Index', [
            'rows' => $paginator,
            'totals' => [
                'billed' => $list->totalBilled->amount(),
                'concession' => $list->totalConcession->amount(),
                'paid' => $list->totalPaid->amount(),
                'outstanding' => $list->totalOutstanding->amount(),
                'overdue' => $list->totalOverdue->amount(),
                'currency' => 'INR',
            ],
            'today' => $list->schoolToday,
            'asAt' => $list->asAt,
            'filters' => [
                'status' => $status,
                'academic_year_id' => $validated['academic_year_id'] ?? '',
                'q' => $search,
                'grade_level_id' => $gradeId ?? '',
                'section_id' => $sectionId ?? '',
                'standing' => $standing ?? '',
                'campus_id' => $campusId ?? '',
                'as_at' => $asAt ?? '',
            ],
            // Only the Campuses the reader's scope covers are offered (a filter, never authority).
            'campuses' => Campus::query()->tap(fn ($q) => $access->restrictCharges($q, $scope, 'id'))->orderBy('name')->get(['id', 'school_id', 'name'])->map(fn (Campus $c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
            'scope' => $scope->isWholeSchool() ? 'school' : 'campus',
            'academicYears' => AcademicYear::query()->orderByDesc('starts_on')->get()->map(fn (AcademicYear $y) => ['id' => $y->id, 'name' => $y->name])->values()->all(),
            'canSeeClass' => $canSeeClass,
            // ORG.9D-2 (ADR 0076 §74): the fee-reminder action is offered only to
            // someone who can also prepare a communication; Communications
            // re-checks both authorities itself (this flag is presentation only).
            'canSendFeeReminder' => Gate::allows('capability', ['communications.announce', $school]),
            'gradeLevels' => $canSeeClass ? GradeLevel::query()->orderBy('sequence')->get()->map(fn (GradeLevel $g) => ['id' => $g->id, 'name' => $g->name])->values()->all() : [],
            'sections' => $canSeeClass && $activeYear !== null
                ? Section::query()->where('academic_year_id', $activeYear->id)->with('gradeLevel')->get()
                    ->sortBy(fn (Section $s) => [$s->gradeLevel?->sequence, $s->name])
                    ->map(fn (Section $s) => ['id' => $s->id, 'label' => "{$s->gradeLevel?->name} · {$s->name}", 'gradeLevelId' => $s->grade_level_id])->values()->all()
                : [],
        ]);
    }
}
