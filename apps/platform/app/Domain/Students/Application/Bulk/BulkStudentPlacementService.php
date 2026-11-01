<?php

namespace App\Domain\Students\Application\Bulk;

use App\Domain\AcademicStructure\Application\CurrentAcademicYearResolver;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\CampusStudentTransferService;
use App\Domain\Students\Application\Exceptions\InvalidEnrollmentTransitionException;
use App\Domain\Students\Application\Exceptions\StudentException;
use App\Domain\Students\Application\SectionOccupancy;
use App\Domain\Students\Application\SectionOccupancyReader;
use App\Domain\Students\Application\StudentAccess;
use App\Domain\Students\Application\StudentEnrollmentReadService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentSearch;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * SIS 11.13 (Bulk Student Operations Foundation): Select → Preview → fresh
 * MFA → revalidate → atomic execute, for exactly two moves
 * (BulkPlacementOperation). It owns NO placement rule: every Student is moved
 * by the existing single-Student primitive --
 *
 * - whole School: StudentEnrollmentService::transferPlacement() (the
 *   Section-change / transfer contract, with the Hostel, Transport and
 *   elective blockers whenever the Campus changes);
 * - a Campus-scoped actor (Campus transfer only):
 *   CampusStudentTransferService::transfer() (dual scope, today, the one
 *   generic refusal).
 *
 * PREVIEW runs those primitives for every selected Student inside ONE
 * transaction that is ALWAYS rolled back: each Student's answer is the
 * authoritative one (blockers, roll numbers -- including two selected
 * Students given the same number --, placement dates, active Section,
 * Campus and year), and nothing is ever committed: no placement, no audit.
 * It takes the same row locks for that moment.
 *
 * EXECUTE accepts only a signed preview in which every row was Ready, then,
 * in one transaction: the School operating, the resolver's scope with its
 * authority re-read fresh (`holdsInSchoolNow()`, or `holdsInCampusNow()` over
 * both Campuses), the
 * signature, every selected Student share-locked in id order (the movement
 * lock order: Students → year → Sections ascending → placements), each row
 * re-classified and moved in id order. Any refusal -- a Student moved since
 * the preview (the placement id the preview saw is no longer current), a
 * blocker, a taken roll number, a lock conflict -- rolls the WHOLE batch
 * back: all or nothing, never a partial batch.
 *
 * Audit: each move writes its own `student_enrollment.transferred` (with the
 * batch id); the batch writes one `student_enrollment.bulk_moved` with ids,
 * codes and counts only -- never a name.
 */
final class BulkStudentPlacementService
{
    public const CAPABILITY = 'enrollments.manage';

    /** Measured in SIS 11.13 (docs/sis/SIS-11-13-BULK-STUDENT-OPERATIONS.md §9): a full Section with headroom. */
    public const MAX_STUDENTS = 100;

    public const PREVIEW_TTL_MINUTES = 30;

    public const AUDIT_EVENT = 'student_enrollment.bulk_moved';

    public const READY = 'ready';

    public const ALREADY_IN_TARGET = 'already_in_target';

    public const STALE = 'stale';

    public const BLOCKED = 'blocked';

    public const STATE_LABELS = [
        self::READY => 'Ready',
        self::ALREADY_IN_TARGET => 'Already in target Section',
        self::STALE => 'Changed since selection',
        self::BLOCKED => 'Blocked',
    ];

    private const UNAVAILABLE = 'This Student is not available for this move.';

    public function __construct(
        private readonly StudentAccess $access,
        private readonly StudentEnrollmentService $enrollments,
        private readonly StudentEnrollmentReadService $placements,
        private readonly CampusStudentTransferService $transfers,
        private readonly CapabilityResolver $capabilities,
        private readonly CurrentAcademicYearResolver $years,
        private readonly SectionOccupancyReader $occupancy,
        private readonly SchoolOperationalGuard $operational,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
    ) {}

    /**
     * Every selected Student's outcome if the batch ran now; commits nothing.
     *
     * @return array<string, mixed>
     */
    public function preview(School $school, User $actor, CampusScope $scope, BulkPlacementRequest $request): array
    {
        $this->assertRowCount($request);

        return $this->context->withSchool($school, function () use ($school, $actor, $scope, $request): array {
            [$source, $target] = $this->resolveContext($school, $scope, $request);
            // Before the trial: the advisory occupancy is today's committed truth.
            $occupancy = $this->occupancy->forSections($school, [$target])[(string) $target->id];
            $facts = $this->facts($school, $scope, $source, $request);

            $rows = [];
            DB::beginTransaction();
            try {
                foreach ($request->rows as $row) {
                    [$state, $message] = $this->classify($row, $facts, $scope, $source, $target);
                    if ($state === self::READY) {
                        try {
                            DB::transaction(fn () => $this->move($school, $scope, $target, $row, $request->effectiveDate, $actor, null));
                        } catch (InvalidEnrollmentTransitionException) {
                            [$state, $message] = [self::STALE, $this->staleMessage($facts, $row, $scope, $source)];
                        } catch (StudentException $e) {
                            [$state, $message] = [self::BLOCKED, $e->getMessage()];
                        } catch (ModelNotFoundException) {
                            [$state, $message] = [self::BLOCKED, self::UNAVAILABLE];
                        }
                    }
                    $rows[] = $this->presentRow($row, $facts, $scope, $state, $message);
                }
            } finally {
                // Always: the preview never commits a move or an audit event.
                DB::rollBack();
            }

            $summary = array_fill_keys(array_keys(self::STATE_LABELS), 0);
            foreach ($rows as $r) {
                $summary[$r['state']]++;
            }
            $executable = $rows !== [] && $summary[self::READY] === count($rows);
            $issuedAt = CarbonImmutable::now()->toIso8601String();

            return [
                'operation' => $request->operation->value,
                'operationLabel' => $request->operation->label(),
                'scope' => $this->scopeKind($scope),
                'source' => $this->presentSection($source),
                'target' => $this->presentSection($target),
                'effectiveDate' => $request->effectiveDate,
                'rows' => $rows,
                'summary' => $summary,
                'capacity' => $this->capacity($occupancy, $summary[self::READY]),
                'executable' => $executable,
                'issuedAt' => $executable ? $issuedAt : null,
                'signature' => $executable ? $this->sign($school, $actor, $scope, $request, $issuedAt) : null,
            ];
        });
    }

    /**
     * The signature and its expiry -- checked BEFORE a fresh MFA code is
     * consumed (the controller) and again inside execute().
     */
    public function assertSigned(School $school, User $actor, CampusScope $scope, BulkPlacementRequest $request, string $issuedAt, string $signature): void
    {
        if (! hash_equals($this->sign($school, $actor, $scope, $request, $issuedAt), $signature)) {
            throw new BulkPlacementRefusedException('This confirmation does not match its preview, or your access changed since. Preview the move again.');
        }
        try {
            $issued = CarbonImmutable::parse($issuedAt);
        } catch (Throwable) {
            throw new BulkPlacementRefusedException('This preview has expired. Preview the move again.');
        }
        if ($issued->subMinute()->isFuture() || $issued->addMinutes(self::PREVIEW_TTL_MINUTES)->isPast()) {
            throw new BulkPlacementRefusedException('This preview has expired. Preview the move again.');
        }
    }

    /**
     * Moves every Student of a signed, all-Ready preview -- or none.
     *
     * @return array<string, mixed> what was moved (for the result page)
     */
    public function execute(School $school, User $actor, BulkPlacementRequest $request, string $issuedAt, string $signature, FreshMfaProof $proof): array
    {
        $this->assertRowCount($request);
        if (! $proof->isFor($actor->id)) {
            throw new LogicException('A fresh MFA proof belongs to the acting user only.');
        }
        $batchId = (string) Str::uuid7();

        try {
            return $this->context->withSchool($school, fn (): array => DB::transaction(function () use ($school, $actor, $request, $issuedAt, $signature, $proof, $batchId): array {
                $this->operational->requireOperational($school->id);
                $scope = $this->currentScope($school, $actor, $request);
                $this->assertSigned($school, $actor, $scope, $request, $issuedAt, $signature);
                [$source, $target] = $this->resolveContext($school, $scope, $request);

                // The movement lock order, batch-wide: every Student FOR SHARE in
                // id order first (each move then takes the year, both Sections in
                // ascending id order and its placement) -- two batches, or a batch
                // and a single transfer, serialize instead of deadlocking.
                $locked = Student::query()->where('school_id', $school->id)->whereIn('id', $request->studentIds())->orderBy('id')->sharedLock()->pluck('id');
                if ($locked->count() !== count($request->rows)) {
                    throw new BulkRowRefusal(null, self::UNAVAILABLE);
                }

                $facts = $this->facts($school, $scope, $source, $request);
                $moved = [];
                foreach ($request->rows as $row) {
                    [$state, $message] = $this->classify($row, $facts, $scope, $source, $target);
                    if ($state !== self::READY) {
                        throw new BulkRowRefusal($row['studentId'], (string) $message, $state === self::ALREADY_IN_TARGET);
                    }
                    try {
                        $placement = $this->move($school, $scope, $target, $row, $request->effectiveDate, $actor, $batchId);
                    } catch (InvalidEnrollmentTransitionException) {
                        throw new BulkRowRefusal($row['studentId'], $this->staleMessage($facts, $row, $scope, $source));
                    } catch (StudentException $e) {
                        throw new BulkRowRefusal($row['studentId'], $e->getMessage());
                    } catch (ModelNotFoundException) {
                        throw new BulkRowRefusal($row['studentId'], self::UNAVAILABLE);
                    }
                    $moved[] = $this->presentRow($row, $facts, $scope, self::READY, null) + ['toRollNumber' => $placement->roll_number];
                }

                // Ids, codes and counts only: never a name (the per-Student events carry the batch id).
                $this->audit->school($school, self::AUDIT_EVENT, actor: $actor, subject: $target, metadata: [
                    'batchId' => $batchId,
                    'operation' => $request->operation->value,
                    'students' => count($moved),
                    'academicYearId' => $source->academic_year_id,
                    'gradeLevelId' => $source->grade_level_id,
                    'fromCampusId' => $source->campus_id,
                    'fromSectionId' => $source->id,
                    'toCampusId' => $target->campus_id,
                    'toSectionId' => $target->id,
                    'effectiveDate' => $request->effectiveDate,
                    'scope' => $this->scopeKind($scope),
                    'mfaVerifiedAt' => $proof->verifiedAt->toIso8601String(),
                ]);

                return [
                    'batchId' => $batchId,
                    'operation' => $request->operation->value,
                    'operationLabel' => $request->operation->label(),
                    'source' => $this->presentSection($source),
                    'target' => $this->presentSection($target),
                    'effectiveDate' => $request->effectiveDate,
                    'moved' => $moved,
                ];
            }));
        } catch (BulkRowRefusal $e) {
            throw $this->notExecuted($school, $actor, $request, $e);
        } catch (QueryException $e) {
            if (! in_array($e->getCode(), ['40P01', '40001', '55P03'], true)) {
                throw $e;
            }
            throw new BulkPlacementNotExecutedException('No Students were moved: another change to these Students or classes happened at the same time. Review the preview and confirm again.', $this->safePreview($school, $actor, $request));
        }
    }

    /**
     * The selection list: the current placements in the source class that the
     * scope may move -- whole School: every current placement of that class's
     * year in it; Campus scope: StudentAccess's current, in-scope placements.
     * Optional search: the shared Student search (name or number words; never
     * contact data). Read-only; advisory (the preview decides).
     *
     * @return list<array<string, mixed>>
     */
    public function sourceRoster(School $school, CampusScope $scope, Section $source, string $search, int $limit): array
    {
        return $this->context->withSchool($school, function () use ($school, $scope, $source, $search, $limit): array {
            $query = $scope->isWholeSchool()
                ? $this->placements->constrainToCurrentPlacement(StudentEnrollment::query(), $source->academic_year_id)
                    ->where('student_enrollments.school_id', $school->id)->where('student_enrollments.section_id', $source->id)
                    ->with('student:id,school_id,student_number,first_name,middle_name,last_name,status')
                : $this->access->enrollmentDirectory($school, $scope, ['section_id' => $source->id, 'status' => 'active']);
            if (trim($search) !== '') {
                $query->whereHas('student', fn ($q) => StudentSearch::anyField($q, $search));
            }

            return $query->limit($limit)->get()
                ->map(fn (StudentEnrollment $e) => [
                    'studentId' => (string) $e->student_id,
                    'enrollmentId' => (string) $e->id,
                    'studentNumber' => $e->student?->student_number,
                    'name' => trim(implode(' ', array_filter([$e->student?->first_name, $e->student?->middle_name, $e->student?->last_name]))),
                    'rollNumber' => $e->roll_number,
                    'studentStatus' => $e->student?->status,
                ])
                ->sortBy(fn (array $r) => [$r['name'], $r['studentNumber']])->values()->all();
        });
    }

    // --- Context ------------------------------------------------------------

    /**
     * The two Sections of the move, decided from the operation's definition:
     * same School, both in reach, different, same year (else Rollover), same
     * Grade (else promotion), same Campus for a Section change and another
     * Campus for a Campus transfer. A Campus-scoped actor transfers Campus
     * only, within the active year, today (the single-Student contract).
     * The per-Student primitive re-decides all of it under its locks.
     *
     * @return array{0: Section, 1: Section}
     */
    private function resolveContext(School $school, CampusScope $scope, BulkPlacementRequest $request): array
    {
        if ($scope->isNone() || (! $scope->isWholeSchool() && $request->operation === BulkPlacementOperation::SectionChange)) {
            // A same-Campus Section change is whole-School (ADR 0076 §47), in bulk as for one Student.
            throw new AuthorizationException('This action is unauthorized.');
        }
        if (! Str::isUuid($request->sourceSectionId) || ! Str::isUuid($request->targetSectionId)) {
            throw BulkPlacementRefusedException::classes();
        }
        $sections = Section::query()->where('sections.school_id', $school->id)
            ->whereIn('sections.id', [$request->sourceSectionId, $request->targetSectionId])
            ->with(['academicYear', 'campus', 'gradeLevel'])->get()->keyBy('id');
        $source = $sections->get($request->sourceSectionId);
        $target = $sections->get($request->targetSectionId);
        if ($source === null || $target === null || ! $scope->covers($source->campus_id) || ! $scope->covers($target->campus_id)) {
            throw BulkPlacementRefusedException::classes();
        }
        if ($source->id === $target->id) {
            throw new BulkPlacementRefusedException('Choose a target class other than the source class.');
        }
        if ($source->academic_year_id !== $target->academic_year_id) {
            throw BulkPlacementRefusedException::crossYear();
        }
        if ($source->grade_level_id !== $target->grade_level_id) {
            throw BulkPlacementRefusedException::gradeChange();
        }
        $sameCampus = $source->campus_id === $target->campus_id;
        if ($request->operation === BulkPlacementOperation::SectionChange && ! $sameCampus) {
            throw new BulkPlacementRefusedException('A Section change stays on the same Campus. To move Students to another Campus, choose Transfer Campus.');
        }
        if ($request->operation === BulkPlacementOperation::CampusTransfer && $sameCampus) {
            throw new BulkPlacementRefusedException('A Campus transfer moves Students to another Campus. For a class on the same Campus, choose Change Section.');
        }
        if (! $scope->isWholeSchool()) {
            if ($source->academic_year_id !== $this->years->tryResolve($school)?->id) {
                throw BulkPlacementRefusedException::classes();
            }
            if ($request->effectiveDate !== SchoolTimezone::today($school)) {
                throw new BulkPlacementRefusedException('A Campus transfer takes effect today.');
            }
        }

        return [$source, $target];
    }

    /**
     * The actor's scope (from CampusScopeResolver, the one source of a
     * CampusScope) AND the authority behind it re-read NOW inside the
     * execution transaction, never the grant cache: a whole-School scope
     * needs `holdsInSchoolNow()`; a Campus scope (a Campus transfer only)
     * needs `holdsInCampusNow()` over BOTH Campuses of the move. A grant
     * revoked since the preview refuses the batch.
     */
    private function currentScope(School $school, User $actor, BulkPlacementRequest $request): CampusScope
    {
        $scope = $this->access->scopeOf($actor, self::CAPABILITY, $school);
        if ($scope->isWholeSchool()) {
            if ($this->capabilities->holdsInSchoolNow($actor, self::CAPABILITY, $school)) {
                return $scope;
            }
        } elseif (! $scope->isNone() && $request->operation === BulkPlacementOperation::CampusTransfer
            && Str::isUuid($request->sourceSectionId) && Str::isUuid($request->targetSectionId)) {
            $campuses = Section::query()->where('school_id', $school->id)->whereIn('id', [$request->sourceSectionId, $request->targetSectionId])->pluck('campus_id', 'id');
            $from = $campuses->get($request->sourceSectionId);
            $to = $campuses->get($request->targetSectionId);
            if (is_string($from) && is_string($to) && $scope->covers($from) && $scope->covers($to)
                && $this->capabilities->holdsInCampusNow($actor, self::CAPABILITY, $school, $from)
                && $this->capabilities->holdsInCampusNow($actor, self::CAPABILITY, $school, $to)) {
                return $scope;
            }
        }

        throw new AuthorizationException('This action is unauthorized.');
    }

    // --- Rows ---------------------------------------------------------------

    /**
     * Constant-count reads for the whole selection: the placements the
     * operator saw, each Student's active placement in the source year, and
     * (Campus scope) which of them the scope reaches now.
     *
     * @return array{placements: Collection<string, StudentEnrollment>, current: Collection<string, StudentEnrollment>, reachable: ?Collection<string, StudentEnrollment>}
     */
    private function facts(School $school, CampusScope $scope, Section $source, BulkPlacementRequest $request): array
    {
        $enrollmentIds = array_values(array_filter(array_column($request->rows, 'enrollmentId'), fn (string $id) => Str::isUuid($id)));
        $studentIds = array_values(array_filter($request->studentIds(), fn (string $id) => Str::isUuid($id)));

        return [
            'placements' => StudentEnrollment::query()->where('school_id', $school->id)->whereIn('id', $enrollmentIds)
                ->with(['student:id,school_id,student_number,first_name,middle_name,last_name,status', 'campus', 'gradeLevel', 'section'])
                ->get()->keyBy('id'),
            // Each Student's current placement in the source class's year (the one definition, ADR 0076 §38).
            'current' => $this->placements->constrainToCurrentPlacement(StudentEnrollment::query(), $source->academic_year_id)
                ->where('student_enrollments.school_id', $school->id)->whereIn('student_enrollments.student_id', $studentIds)
                ->with(['campus', 'gradeLevel', 'section'])->get()->keyBy('student_id'),
            'reachable' => $scope->isWholeSchool() ? null : $this->access->currentPlacements($school, $scope, $studentIds),
        ];
    }

    /**
     * Ready, already in the target, changed since selection, or not
     * available -- from the facts; a Ready row is then tried by the
     * primitive itself.
     *
     * @param  array{studentId: string, enrollmentId: string, rollNumber: string}  $row
     * @param  array{placements: Collection<string, StudentEnrollment>, current: Collection<string, StudentEnrollment>, reachable: ?Collection<string, StudentEnrollment>}  $facts
     * @return array{0: string, 1: ?string}
     */
    private function classify(array $row, array $facts, CampusScope $scope, Section $source, Section $target): array
    {
        $seen = $facts['placements']->get($row['enrollmentId']);
        if ($seen === null || $seen->student_id !== $row['studentId']
            || ($facts['reachable'] !== null && ! $facts['reachable']->has($row['studentId']))) {
            return [self::BLOCKED, self::UNAVAILABLE];
        }
        $current = $facts['current']->get($row['studentId']);
        if ($current !== null && $current->section_id === $target->id) {
            return [self::ALREADY_IN_TARGET, 'Already in this target class: nothing to move.'];
        }
        if ($seen->status !== 'active' || $seen->section_id !== $source->id || $current?->id !== $seen->id) {
            return [self::STALE, $this->staleMessage($facts, $row, $scope, $source)];
        }

        return [self::READY, null];
    }

    /** @param  array{studentId: string, enrollmentId: string, rollNumber: string}  $row */
    private function move(School $school, CampusScope $scope, Section $target, array $row, string $effectiveDate, User $actor, ?string $batchId): StudentEnrollment
    {
        if ($scope->isWholeSchool()) {
            $seen = StudentEnrollment::query()->where('school_id', $school->id)->whereKey($row['enrollmentId'])->firstOrFail();

            return $this->enrollments->transferPlacement($seen, $target, $row['rollNumber'], $effectiveDate, $actor, null, $batchId);
        }

        return $this->transfers->transfer($school, $scope, $row['studentId'], $target->id, $row['rollNumber'], $actor, $row['enrollmentId'], $batchId);
    }

    /**
     * @param  array{placements: Collection<string, StudentEnrollment>, current: Collection<string, StudentEnrollment>, reachable: ?Collection<string, StudentEnrollment>}  $facts
     * @param  array{studentId: string, enrollmentId: string, rollNumber: string}  $row
     */
    private function staleMessage(array $facts, array $row, CampusScope $scope, Section $source): string
    {
        $current = $facts['current']->get($row['studentId']);
        // Never name a class the actor's scope does not reach.
        $now = $current !== null && $scope->covers($current->campus_id)
            ? "now in {$current->gradeLevel?->name} · Section {$current->section?->name} · {$current->campus?->name}"
            : 'no longer in the source class';

        return "Changed since it was selected: {$now}. Remove this Student or select again.";
    }

    /**
     * @param  array{studentId: string, enrollmentId: string, rollNumber: string}  $row
     * @param  array{placements: Collection<string, StudentEnrollment>, current: Collection<string, StudentEnrollment>, reachable: ?Collection<string, StudentEnrollment>}  $facts
     * @return array<string, mixed>
     */
    private function presentRow(array $row, array $facts, CampusScope $scope, string $state, ?string $message): array
    {
        $seen = $facts['placements']->get($row['enrollmentId']);
        // An unavailable row (unknown, another School's, out of scope) reveals nothing.
        $visible = $seen !== null && $seen->student_id === $row['studentId'] && $message !== self::UNAVAILABLE;
        $student = $visible ? $seen->student : null;

        return [
            'studentId' => $row['studentId'],
            'enrollmentId' => $row['enrollmentId'],
            'rollNumber' => $row['rollNumber'],
            'studentNumber' => $student?->student_number,
            'name' => $student === null ? null : trim(implode(' ', array_filter([$student->first_name, $student->middle_name, $student->last_name]))),
            'from' => $visible ? [
                'campus' => $seen->campus?->name,
                'gradeLevel' => $seen->gradeLevel?->name,
                'section' => $seen->section?->name,
                'rollNumber' => $seen->roll_number,
            ] : null,
            'state' => $state,
            'stateLabel' => self::STATE_LABELS[$state],
            'message' => $message,
        ];
    }

    // --- Presentation -------------------------------------------------------

    /** @return array<string, mixed> */
    private function presentSection(Section $section): array
    {
        return [
            'id' => (string) $section->id,
            'academicYear' => $section->academicYear?->name,
            'campus' => $section->campus?->name,
            'gradeLevel' => $section->gradeLevel?->name,
            'section' => $section->name,
            'label' => "{$section->gradeLevel?->name} · Section {$section->name} · {$section->campus?->name} · {$section->academicYear?->name}",
        ];
    }

    /**
     * SIS 11.3's advisory occupancy of the target, now and after the batch
     * (Students already in the target are not counted twice: only Ready rows
     * move). A warning, never a refusal.
     *
     * @return array<string, mixed>
     */
    private function capacity(SectionOccupancy $now, int $moving): array
    {
        $after = new SectionOccupancy($now->capacity, $now->strength === null ? null : $now->strength + $moving, $now->asOf);

        return [
            'now' => $now->label(),
            'after' => $after->label(),
            'moving' => $moving,
            'warning' => $after->state() === SectionOccupancy::OVER_CAPACITY
                ? 'After this move the target class is over its advisory capacity by '.abs((int) $after->available()).'. This is a warning only: the move is still allowed.'
                : null,
        ];
    }

    // --- Refusal ------------------------------------------------------------

    private function notExecuted(School $school, User $actor, BulkPlacementRequest $request, BulkRowRefusal $refusal): BulkPlacementNotExecutedException
    {
        $preview = $this->safePreview($school, $actor, $request);
        $rows = $preview['rows'] ?? [];
        if ($rows !== [] && count(array_filter($rows, fn (array $r) => $r['state'] === self::ALREADY_IN_TARGET)) === count($rows)) {
            return new BulkPlacementNotExecutedException('No Students were moved: every selected Student is already in the target class. This batch may already have been completed.', $preview);
        }
        $who = null;
        foreach ($rows as $r) {
            if ($r['studentId'] === $refusal->studentId && $r['name'] !== null) {
                $who = "{$r['name']} ({$r['studentNumber']})";
            }
        }

        return new BulkPlacementNotExecutedException('No Students were moved. '.($who === null ? '' : "{$who}: ").$refusal->getMessage().' Review the new preview below.', $preview);
    }

    /** @return array<string, mixed>|null a fresh preview of the same request, or null when the request itself is no longer previewable */
    private function safePreview(School $school, User $actor, BulkPlacementRequest $request): ?array
    {
        try {
            return $this->preview($school, $actor, $this->access->scopeOf($actor, self::CAPABILITY, $school), $request);
        } catch (StudentException|AuthorizationException) {
            return null;
        }
    }

    // --- Helpers ------------------------------------------------------------

    private function assertRowCount(BulkPlacementRequest $request): void
    {
        $count = count($request->rows);
        if ($count === 0) {
            throw new BulkPlacementRefusedException('Select at least one Student.');
        }
        if ($count > self::MAX_STUDENTS) {
            throw new BulkPlacementRefusedException('A bulk move takes at most '.self::MAX_STUDENTS.' Students at a time.');
        }
        if (count(array_unique($request->studentIds())) !== $count) {
            throw new BulkPlacementRefusedException('Each Student can be selected only once.');
        }
    }

    private function scopeKind(CampusScope $scope): string
    {
        return $scope->isWholeSchool() ? 'school' : 'campus';
    }

    private function sign(School $school, User $actor, CampusScope $scope, BulkPlacementRequest $request, string $issuedAt): string
    {
        $key = hash_hmac('sha256', 'student-bulk-placement', (string) config('app.key'));

        return hash_hmac('sha256', json_encode([$school->id, $actor->id, $this->scopeKind($scope), $issuedAt, $request->canonical()], JSON_THROW_ON_ERROR), $key);
    }
}
