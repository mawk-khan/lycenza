<?php

namespace Tests\Feature\Postgres;

use App\Domain\HR\Application\AccountLinks\EmployeeAccountLinkCorrectionService;
use App\Domain\HR\Application\DepartmentService;
use App\Domain\HR\Application\EmployeeAddressService;
use App\Domain\HR\Application\EmployeeCategoryService;
use App\Domain\HR\Application\EmployeeCertificationService;
use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Application\EmployeeEmergencyContactService;
use App\Domain\HR\Application\EmployeeExperienceService;
use App\Domain\HR\Application\EmployeeNoteService;
use App\Domain\HR\Application\EmployeePersonalDetailService;
use App\Domain\HR\Application\EmployeeQualificationService;
use App\Domain\HR\Application\EmployeeService;
use App\Domain\HR\Application\EmploymentService;
use App\Domain\HR\Application\PositionService;
use App\Domain\HR\Application\Profile\EmployeeDocumentCategory;
use App\Domain\HR\Application\Profile\EmployeeReferenceCodeService;
use App\Domain\HR\Application\Profile\EmployeeReferenceKind;
use App\Domain\HR\Application\Reporting\ReportingChangeReason;
use App\Domain\HR\Application\Reporting\ReportingRelationshipService;
use App\Domain\HR\Application\Separation\ClearanceResponsibility;
use App\Domain\HR\Application\Separation\SeparationCaseService;
use App\Domain\HR\Application\Separation\SeparationClearanceRequirementService;
use App\Domain\HR\Application\Separation\SeparationReason;
use App\Domain\HR\Application\Workforce\PlacementChangeKind;
use App\Domain\HR\Application\Workforce\PlacementChangeReason;
use App\Domain\HR\Application\Workforce\PlacementChangeService;
use App\Domain\HR\Infrastructure\EmployeeAccountLink;
use App\Domain\Leave\Application\Accrual\LeaveAccrualService;
use App\Domain\Leave\Application\DayPortion;
use App\Domain\Leave\Application\LeaveEligibilityService;
use App\Domain\Leave\Application\LeaveLedgerService;
use App\Domain\Leave\Application\LeavePolicyAssignmentService;
use App\Domain\Leave\Application\LeavePolicyService;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\LeaveYearCloseService;
use App\Domain\Leave\Application\LeaveYearService;
use App\Domain\Leave\Application\Policy\LeaveEligibilityCriteria;
use App\Domain\StaffAttendance\Application\StaffAttendanceService;
use App\Domain\WorkforceTasks\Application\TaskAssignmentService;
use App\Domain\WorkforceTasks\Application\TaskCatalogueService;
use App\Domain\WorkforceTasks\Application\TaskGenerationService;
use App\Models\School;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsHrFamilyIsolation;
use Tests\Feature\HR\Concerns\CreatesLinkedHrStaff;
use Tests\Feature\StaffAttendance\Concerns\CreatesStaffAttendanceFixtures;
use Tests\TestCase;

/**
 * HR.R1-C35 WO-26 (R1-06: "Raw-SQL / RLS tests for every HR table", rule 28; C38-N1, C38-D2).
 *
 * The coverage contract is the verifier's own family list, `DatabaseRoleVerifier::HR_FAMILY_RUNTIME_PRIVILEGES`:
 * a table added to or removed from it fails `the_manifest_is_the_verifiers_family_list` until it is classified here.
 * Two Schools each get one row (at least) in every one of those tables, written by the real services as the runtime
 * role -- never an empty table, so no zero is vacuous. `AssertsHrFamilyIsolation` then probes each table on the raw
 * runtime connection: positive control, foreign read by count and by key, no context (reset and empty), a foreign
 * insert, a foreign update and a move between Schools, each refusal checked for its cause.
 *
 * `staff_working_weekdays` is retired and read-only for the runtime role (no application writer exists, HR.R1-C22),
 * so it is seeded on the admin connection in its own committing test, `HrFamilyRetiredWeekdayIsolationTest`.
 * That the checker itself can fail is proven by `HrFamilyIsolationCheckerSelfTest`.
 */
class HrFamilyTenantIsolationTest extends TestCase
{
    use AssertsHrFamilyIsolation, CreatesLinkedHrStaff, CreatesStaffAttendanceFixtures;

    /**
     * Every family table: how its rows are made, and a guard that may answer a foreign write before RLS does
     * (a regex on SQLSTATE + message). Without a guard the refusal must be RLS itself.
     *
     * `born` resets a copied row to the state the table admits at insert, so its business guard passes and RLS is what
     * answers the foreign insert. `insert` declares the three evidence tables whose guard refuses EVERY direct insert,
     * in any School (written only inside approval, a processing accrual run, or a correction's own transaction): there
     * the leg proves refusal, and the catalogue pin proves the WITH CHECK. `move` names the table's own immutability
     * guard, which refuses moving a row between Schools before RLS is asked (the C10 precedent). `refused` pins how
     * each foreign insert and move was refused (rls, guard_under_rls: a guard refusing only in the other School's
     * context, evidence_guard, immutability_guard, no_privilege), so a change of class fails instead of passing quietly.
     *
     * @var array<string, array{fixture: string, refused?: array{insert: string, move: string}, insert?: string, move?: string, born?: array<string, mixed>}>
     */
    private const MANIFEST = [
        // HR
        'employee_account_link_corrections' => ['fixture' => 'EmployeeAccountLinkCorrectionService request + approve', 'born' => ['status' => 'requested', 'decided_by_user_id' => null, 'decided_at' => null], 'move' => '/a decision is final/', 'refused' => ['insert' => 'guard_under_rls', 'move' => 'immutability_guard']],
        'employee_account_links' => ['fixture' => 'trigger on EmployeeService::linkUser / unlinkUser', 'refused' => ['insert' => 'no_privilege', 'move' => 'no_privilege']],
        'employee_addresses' => ['fixture' => 'EmployeeAddressService::add', 'move' => '/never changes its Employee/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'employee_assignments' => ['fixture' => 'factory + PlacementChangeService', 'refused' => ['insert' => 'rls', 'move' => 'rls']],
        'employee_categories' => ['fixture' => 'EmployeeCategoryService::create', 'refused' => ['insert' => 'rls', 'move' => 'rls']],
        'employee_certifications' => ['fixture' => 'EmployeeCertificationService::add', 'move' => '/never changes its Employee/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'employee_credential_verifications' => ['fixture' => 'EmployeeQualificationService::verify', 'refused' => ['insert' => 'guard_under_rls', 'move' => 'no_privilege']],
        'employee_documents' => ['fixture' => 'EmployeeDocumentService::register', 'move' => '/never changes its file or its Employee/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'employee_emergency_contacts' => ['fixture' => 'EmployeeEmergencyContactService::add', 'move' => '/never changes its Employee/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'employee_experience_records' => ['fixture' => 'EmployeeExperienceService::add', 'move' => '/never changes its Employee/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'employee_notes' => ['fixture' => 'EmployeeNoteService::add', 'move' => '/never changes its Employee/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'employee_personal_details' => ['fixture' => 'EmployeePersonalDetailService::setDetails', 'refused' => ['insert' => 'rls', 'move' => 'rls']],
        'employee_placement_changes' => ['fixture' => 'PlacementChangeService::change', 'refused' => ['insert' => 'guard_under_rls', 'move' => 'no_privilege']],
        'employee_qualifications' => ['fixture' => 'EmployeeQualificationService::add', 'born' => ['verification_status' => 'unverified', 'verified_at' => null, 'revision' => 1, 'verification_revision' => null], 'move' => '/never changes its Employee/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'employee_reference_codes' => ['fixture' => 'EmployeeReferenceCodeService::record', 'move' => '/a reference code is immutable/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'employees' => ['fixture' => 'EmployeeService::create + factory', 'move' => '/number and School are immutable/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'employment_records' => ['fixture' => 'EmploymentService::create + factory', 'move' => '/the identity and start of a record are fixed/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'employment_separation_cases' => ['fixture' => 'SeparationCaseService::record + approve', 'born' => ['status' => 'requested', 'version' => 1, 'decided_by_user_id' => null, 'decided_at' => null, 'clearance_staff_type' => null, 'clearance_skipped_requirement_ids' => null], 'move' => '/separation case terms are immutable/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'employment_separation_events' => ['fixture' => 'SeparationCaseService::record + approve', 'refused' => ['insert' => 'rls', 'move' => 'no_privilege']],
        'employment_terms' => ['fixture' => 'trigger on employment insert', 'refused' => ['insert' => 'guard_under_rls', 'move' => 'no_privilege']],
        'hr_departments' => ['fixture' => 'DepartmentService::create', 'refused' => ['insert' => 'rls', 'move' => 'rls']],
        'hr_employee_number_counters' => ['fixture' => 'EmployeeService::create (EmployeeNumberAllocator)', 'refused' => ['insert' => 'rls', 'move' => 'rls']],
        'positions' => ['fixture' => 'PositionService::create', 'refused' => ['insert' => 'rls', 'move' => 'rls']],
        'reporting_relationships' => ['fixture' => 'ReportingRelationshipService::assign', 'move' => '/parties and start are immutable/', 'refused' => ['insert' => 'guard_under_rls', 'move' => 'immutability_guard']],
        'separation_clearance_items' => ['fixture' => 'SeparationCaseService::approve', 'move' => '/cleared once, never edited/', 'refused' => ['insert' => 'guard_under_rls', 'move' => 'immutability_guard']],
        'separation_clearance_requirements' => ['fixture' => 'SeparationClearanceRequirementService::create', 'move' => '/retired, never edited/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        // Leave, including the staff calendar
        'leave_accrual_run_items' => ['fixture' => 'LeaveAccrualService::execute', 'insert' => '/evidence is written only by a processing run/', 'refused' => ['insert' => 'evidence_guard', 'move' => 'no_privilege']],
        'leave_accrual_runs' => ['fixture' => 'LeaveAccrualService::execute', 'move' => '/a run\'s identity never changes/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'leave_allocation_runs' => ['fixture' => 'LeaveLedgerService::executeRun', 'refused' => ['insert' => 'rls', 'move' => 'no_privilege']],
        'leave_decisions' => ['fixture' => 'LeaveRequestService::approve', 'refused' => ['insert' => 'rls', 'move' => 'no_privilege']],
        'leave_eligibility_rules' => ['fixture' => 'LeavePolicyService::create with a rule', 'refused' => ['insert' => 'guard_under_rls', 'move' => 'no_privilege']],
        'leave_eligibility_runs' => ['fixture' => 'LeaveEligibilityService::executeRun', 'refused' => ['insert' => 'rls', 'move' => 'no_privilege']],
        'leave_ledger_entries' => ['fixture' => 'allocation run, consumption, close, accrual', 'refused' => ['insert' => 'rls', 'move' => 'no_privilege']],
        'leave_policies' => ['fixture' => 'LeavePolicyService::create', 'move' => '/a policy is immutable/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'leave_policy_assignments' => ['fixture' => 'LeavePolicyAssignmentService::assign', 'move' => '/the only change is ending it/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'leave_request_days' => ['fixture' => 'LeaveRequestService::submitOnBehalf', 'insert' => '/evidence is written while approving/', 'refused' => ['insert' => 'evidence_guard', 'move' => 'no_privilege']],
        'leave_requests' => ['fixture' => 'LeaveRequestService::submitOnBehalf', 'born' => ['status' => 'submitted'], 'move' => '/a request never changes after submission/', 'refused' => ['insert' => 'guard_under_rls', 'move' => 'immutability_guard']],
        'leave_settings' => ['fixture' => 'LeaveYearService::setStartMonth', 'refused' => ['insert' => 'rls', 'move' => 'rls']],
        'leave_types' => ['fixture' => 'LeaveTypeService::create', 'move' => '/identity and code never change/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'leave_year_close_items' => ['fixture' => 'LeaveYearCloseService::execute', 'refused' => ['insert' => 'rls', 'move' => 'no_privilege']],
        'leave_year_close_reconciliations' => ['fixture' => 'LeaveRequestService::cancel after the close', 'refused' => ['insert' => 'rls', 'move' => 'no_privilege']],
        'leave_year_closes' => ['fixture' => 'LeaveYearCloseService::execute', 'refused' => ['insert' => 'rls', 'move' => 'no_privilege']],
        'leave_year_start_changes' => ['fixture' => 'LeaveYearService::scheduleStartChange', 'refused' => ['insert' => 'rls', 'move' => 'no_privilege']],
        'leave_years' => ['fixture' => 'LeaveYearService::open', 'refused' => ['insert' => 'rls', 'move' => 'no_privilege']],
        'staff_holidays' => ['fixture' => 'StaffCalendarService::addHoliday', 'move' => '/the terms of a holiday are frozen/', 'refused' => ['insert' => 'guard_under_rls', 'move' => 'immutability_guard']],
        'staff_working_week_versions' => ['fixture' => 'StaffCalendarFixture cutover (owner seam)', 'born' => ['status' => 'active', 'supersedes_id' => null, 'reason_code' => null, 'status_changed_by_user_id' => null, 'status_changed_at' => null], 'move' => '/the terms of a version are frozen/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
        'staff_working_weekdays' => ['fixture' => 'admin seed: HrFamilyRetiredWeekdayIsolationTest'],
        // Staff Attendance
        'staff_attendance_corrections' => ['fixture' => 'StaffAttendanceService::correct', 'insert' => '/a correction starts from the record\'s current version/', 'refused' => ['insert' => 'evidence_guard', 'move' => 'no_privilege']],
        'staff_attendance_locked_corrections' => ['fixture' => 'StaffAttendanceService::requestLockedCorrection + approve', 'born' => ['status' => 'requested', 'decided_by_user_id' => null, 'decided_at' => null], 'move' => '/a decision is final/', 'refused' => ['insert' => 'guard_under_rls', 'move' => 'immutability_guard']],
        'staff_attendance_month_locks' => ['fixture' => 'StaffAttendanceService::lockMonth', 'refused' => ['insert' => 'guard_under_rls', 'move' => 'no_privilege']],
        'staff_attendance_records' => ['fixture' => 'StaffAttendanceService::record', 'born' => ['version' => 1, 'attendance_date' => '2026-06-12'], 'move' => '/only the halves change, by correction/', 'refused' => ['insert' => 'guard_under_rls', 'move' => 'immutability_guard']],
        // WorkforceTasks
        'workforce_task_categories' => ['fixture' => 'TaskCatalogueService::createCategory', 'refused' => ['insert' => 'rls', 'move' => 'rls']],
        'workforce_task_events' => ['fixture' => 'TaskAssignmentService::assign', 'refused' => ['insert' => 'rls', 'move' => 'no_privilege']],
        'workforce_task_generation_runs' => ['fixture' => 'TaskGenerationService::run', 'refused' => ['insert' => 'rls', 'move' => 'rls']],
        'workforce_task_template_versions' => ['fixture' => 'TaskCatalogueService::createTemplate', 'refused' => ['insert' => 'rls', 'move' => 'no_privilege']],
        'workforce_task_templates' => ['fixture' => 'TaskCatalogueService::createTemplate', 'refused' => ['insert' => 'rls', 'move' => 'rls']],
        'workforce_tasks' => ['fixture' => 'TaskGenerationService::run + TaskAssignmentService::assign', 'move' => '/terms and responsibility are immutable/', 'refused' => ['insert' => 'rls', 'move' => 'immutability_guard']],
    ];

    /** Per School: an ended, uncorrected link (and its Employee), the one a probe correction may name. */
    private array $spareLink = [];

    /** The evidence prefixes `AssertsHrFamilyIsolation` records, as the codes `refused` pins. */
    private const REFUSAL_CLASSES = [
        'RLS' => 'rls', 'guard reading under RLS' => 'guard_under_rls', 'declared evidence guard' => 'evidence_guard',
        'declared immutability guard' => 'immutability_guard', 'no INSERT privilege' => 'no_privilege', 'no UPDATE privilege' => 'no_privilege',
    ];

    /** Seeded and probed by its own committing test (no runtime writer exists). */
    private const SEEDED_ELSEWHERE = ['staff_working_weekdays'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function the_manifest_is_the_verifiers_family_list(): void
    {
        $family = array_keys(DatabaseRoleVerifier::HR_FAMILY_RUNTIME_PRIVILEGES);
        $manifest = array_keys(self::MANIFEST);
        sort($family);
        sort($manifest);

        $this->assertSame($family, $manifest, 'every HR-family table has exactly one isolation entry, and nothing else does');
        $this->assertCount(57, $family);
    }

    #[Test]
    public function every_family_table_is_forced_with_exactly_the_tenant_policy(): void
    {
        $violations = $this->roleViolations();
        foreach (array_keys(self::MANIFEST) as $table) {
            array_push($violations, ...$this->policyViolations($table));
        }

        $this->assertSame([], $violations, implode("\n", $violations));
    }

    #[Test]
    public function every_family_table_isolates_each_school_on_the_raw_runtime_connection(): void
    {
        $a = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $b = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->world($a);
        $this->world($b);

        $violations = $this->roleViolations();
        $probed = 0;
        foreach (self::MANIFEST as $table => $entry) {
            if (in_array($table, self::SEEDED_ELSEWHERE, true)) {
                continue;
            }
            foreach ([[$a, $b], [$b, $a]] as [$own, $other]) {
                $declared = array_intersect_key($entry, ['insert' => 1, 'move' => 1, 'born' => 1]);
                if ($table === 'employee_account_link_corrections') {
                    // The fixture's own link is corrected now, so a copy must name the School's spare, uncorrected one.
                    $declared['born'] = [...$declared['born'], ...$this->spareLink[$own->id]];
                }
                if ($table === 'staff_working_week_versions') {
                    // A future week, from the database's own School-local today (the guard's clock), never a fixed date.
                    $declared['born']['effective_from'] = (string) app(TenantContext::class)->withSchool($own,
                        fn () => DB::connection('pgsql')->selectOne('select (staff_calendar_local_today(?::uuid) + 7)::text as d', [$own->id])->d);
                }
                array_push($violations, ...$this->isolationViolations($table, $own->id, $other->id, $declared));
                // The refusal class of each leg is pinned: drift (say from RLS to a guard) fails here, never silently.
                foreach (['insert', 'move'] as $leg) {
                    $actual = self::REFUSAL_CLASSES[strtok($this->isolationEvidence["{$table} {$leg}"] ?? 'missing:', ':')] ?? 'missing';
                    if ($actual !== ($entry['refused'][$leg] ?? null)) {
                        $violations[] = "{$table}: {$leg} refusal class: expected ".($entry['refused'][$leg] ?? '?')."; actual {$actual} (".($this->isolationEvidence["{$table} {$leg}"] ?? 'none').')';
                    }
                }
                if (($this->isolationEvidence["{$table} delete"] ?? null) !== 'no DELETE privilege') {
                    $violations[] = "{$table}: delete: expected no DELETE privilege; actual ".($this->isolationEvidence["{$table} delete"] ?? 'none');
                }
            }
            $probed++;
        }

        $this->assertSame(56, $probed, 'every table but the admin-seeded one was probed here');
        // How each foreign write was refused, for review (git-ignored; never an assertion input).
        ksort($this->isolationEvidence);
        file_put_contents(storage_path('framework/testing/hr-family-isolation-evidence.json'), json_encode($this->isolationEvidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->assertSame([], $violations, implode("\n", $violations));
    }

    /** One School's rows in 56 of the 57 tables, through the real services (the recipe of WO-26's fixture audit). */
    private function world(School $s): void
    {
        Storage::fake((string) config('documents.disk'));
        $hr = $this->createUserWithCapabilities($s, [
            'hr.employees.view', 'hr.employees.manage', 'hr.employees.personal.view', 'hr.employees.personal.manage',
            'hr.employees.assignments.view', 'hr.employees.assignments.manage', 'hr.employees.qualifications.view', 'hr.employees.qualifications.manage',
            'hr.employees.documents.view', 'hr.employees.documents.manage', 'hr.employees.notes.view', 'hr.employees.notes.manage',
            'hr.departments.view', 'hr.departments.manage', 'hr.positions.view', 'hr.positions.manage', 'hr.categories.view', 'hr.categories.manage',
            'hr.separations.view', 'hr.separations.manage', 'hr.separations.configure', 'hr.tasks.view', 'hr.tasks.configure', 'hr.tasks.manage',
        ]);
        $approver = $this->createUserWithCapabilities($s, ['hr.separations.view', 'hr.separations.approve']);
        $admin = $this->leaveAdmin($s);
        $clerk = $this->attendanceAdmin($s);
        $lockKeys = ['hr.staff_attendance.view', 'hr.staff_attendance.manage', 'hr.staff_attendance.lock'];
        $locker = $this->createUserWithCapabilities($s, $lockKeys);
        $reviewer = $this->createUserWithCapabilities($s, $lockKeys);
        $requester = $this->linkedHrStaff($s);
        $linkReviewer = $this->linkedHrStaff($s);

        // The calendar history first (an owner-seam cutover, as every fixture does), outside the School scope.
        $this->workingWeek($s, $admin);

        $this->inSchool($s, function () use ($s, $hr, $approver, $admin, $clerk, $locker, $reviewer, $requester, $linkReviewer): void {
            // HR reference data.
            app(DepartmentService::class)->create($s, ['name' => 'Science', 'code' => 'SCI'], $hr);
            $teacher = app(PositionService::class)->create($s, ['name' => 'Teacher', 'code' => 'TCH'], $hr);
            $coordinator = app(PositionService::class)->create($s, ['name' => 'Coordinator', 'code' => 'CRD'], $hr);
            $category = app(EmployeeCategoryService::class)->create($s, ['name' => 'Contract', 'code' => 'CON'], $hr);

            // A leaver through the services (counter, Employee, employment, terms), then the separation tables.
            $leaver = app(EmploymentService::class)->create(app(EmployeeService::class)->create($s, ['full_name' => 'Leaver Person'], $hr),
                ['employment_type' => 'permanent', 'staff_type' => 'teaching', 'starts_on' => '2024-01-01'], $hr);
            app(SeparationClearanceRequirementService::class)->create($s, 'keys', 'Keys returned', ClearanceResponsibility::HR, true, null, $hr);
            $cases = app(SeparationCaseService::class);
            $case = $cases->record($s, $leaver->id, SeparationReason::RESIGNATION, '2026-06-15', '2026-07-31', $hr);
            $cases->approve($s, $case->id, $case->version, $approver, $this->freshMfaProofFor($approver));

            // The profile subject and a placed manager.
            $subject = $this->createEmployee($s);
            $subjectEmployment = $this->createEmploymentRecord($subject, ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null, 'staff_type' => 'teaching']);
            $manager = $this->createEmploymentRecord($this->createEmployee($s), ['status' => 'active', 'starts_on' => '2024-01-01', 'ends_on' => null, 'staff_type' => 'teaching']);
            $placement = $this->createEmployeeAssignment($manager, $teacher, ['starts_on' => '2024-01-01', 'ends_on' => null, 'is_primary' => true]);
            app(PlacementChangeService::class)->change($placement, PlacementChangeKind::TRANSFER, '2026-06-01', PlacementChangeReason::STAFFING_REQUIREMENT, $coordinator, $hr, campus: $this->createCampus($s));
            app(ReportingRelationshipService::class)->assign($subjectEmployment, $manager, '2026-01-01', ReportingChangeReason::ORGANISATIONAL_CHANGE, $hr);
            app(EmployeeAddressService::class)->add($subject, ['address_type' => 'current', 'address_line1' => '1 Road', 'city' => 'Pune', 'country_code' => 'IN'], $hr);
            app(EmployeeEmergencyContactService::class)->add($subject, ['name' => 'Kin Person', 'relationship' => 'sibling', 'phone' => '+919800000001'], $hr);
            app(EmployeePersonalDetailService::class)->setDetails($subject, ['nationality' => 'Indian'], $hr);
            app(EmployeeNoteService::class)->add($subject, ['body' => 'A synthetic note', 'classification_tier' => 'sensitive'], $hr);
            $qualification = app(EmployeeQualificationService::class)->add($subject, ['qualification_type' => 'degree', 'qualification_name' => 'BSc', 'institution' => 'A College'], $hr);
            app(EmployeeQualificationService::class)->verify($subject, $qualification, $hr, 1);
            app(EmployeeExperienceService::class)->add($subject, ['organization' => 'An Org', 'job_title' => 'Teacher', 'starts_on' => '2010-01-01'], $hr);
            app(EmployeeCertificationService::class)->add($subject, ['name' => 'A Cert', 'issuer' => 'A Board'], $hr);
            app(EmployeeReferenceCodeService::class)->record($subject, EmployeeReferenceKind::ERP, 'ERP-1', $hr);
            app(EmployeeDocumentService::class)->register($subject, UploadedFile::fake()->createWithContent('record.pdf', "%PDF-1.4\n%x\n"), EmployeeDocumentCategory::EMPLOYMENT_CONTRACT, 'restricted', [], $hr);

            // An account link, ended, then corrected by two linked staff.
            $linked = $this->createEmployee($s);
            $account = $this->createUser();
            $this->createMembership($account, $s);
            app(EmployeeService::class)->linkUser($linked, $account->id, $hr);
            app(EmployeeService::class)->unlinkUser($linked->fresh(), $hr);
            $link = EmployeeAccountLink::query()->where('employee_id', $linked->id)->firstOrFail();
            $this->approveCorrection($s, $this->requestCorrection($linked, $link->id, $requester), $linkReviewer);
            $spare = $this->createEmployee($s);
            $spareAccount = $this->createUser();
            $this->createMembership($spareAccount, $s);
            app(EmployeeService::class)->linkUser($spare, $spareAccount->id, $hr);
            app(EmployeeService::class)->unlinkUser($spare->fresh(), $hr);
            $spareLink = EmployeeAccountLink::query()->where('employee_id', $spare->id)->firstOrFail();
            $this->spareLink[$s->id] = ['employee_id' => $spare->id, 'account_link_id' => $spareLink->id,
                'evidence_version' => app(EmployeeAccountLinkCorrectionService::class)->version($s->id, $spareLink->id)];

            // Tasks.
            $tasks = app(TaskCatalogueService::class);
            $taskCategory = $tasks->createCategory($s, 'admin', 'Administrative', $hr);
            $tasks->createTemplate($s, 'daily', [
                'title' => 'Open the office', 'category_id' => $taskCategory->id, 'completion_mode' => 'self', 'frequency' => 'daily', 'weekdays' => 127, 'month_day' => null,
                'last_day' => false, 'year_month' => null, 'due_days' => 0, 'applies_teaching' => true, 'applies_non_teaching' => false, 'applies_outsourced' => false,
                'effective_from' => '2026-06-15',
            ], $hr);
            app(TaskGenerationService::class)->run($s);
            app(TaskAssignmentService::class)->assign($s, $manager->id, [
                'title' => 'Prepare the notice board', 'category_id' => $taskCategory->id, 'completion_mode' => 'reviewed', 'available_on' => '2026-06-15', 'due_on' => '2026-06-20',
            ], $hr);

            // Leave: settings before any year, two years, a scheduled start change, three types and their policies.
            $years = app(LeaveYearService::class);
            $years->setStartMonth($s, 4, $admin);
            $closedYear = $years->open($s, '2025-06-01', $admin);
            $currentYear = $years->open($s, '2026-06-01', $admin);
            $years->scheduleStartChange($s, 1, '2028-01-01', $admin);
            $casual = $this->leaveType($s, $admin, 'CL');
            $casualPolicy = $this->leavePolicy($s, $casual, $admin);
            $earned = $this->leaveType($s, $admin, 'EL');
            $earnedPolicy = app(LeavePolicyService::class)->create($s, $earned->id, [
                'name' => 'Monthly', 'annual_allocation_units' => 0, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null, 'carry_forward_expiry_days' => null,
                'entitlement_method' => 'monthly', 'monthly_accrual_units' => 3, 'accrual_timing' => 'period_start',
            ], null, $admin);
            $special = $this->leaveType($s, $admin, 'SL');
            app(LeavePolicyService::class)->create($s, $special->id, [
                'name' => 'Ruled', 'annual_allocation_units' => 0, 'carry_forward_allowed' => false, 'carry_forward_cap_units' => null, 'carry_forward_expiry_days' => null,
            ], null, $admin, [new LeaveEligibilityCriteria(null, $category->id, null)]);
            app(LeaveEligibilityService::class)->executeRun($s, $special->id, '2026-06-15', $admin);
            $assignments = app(LeavePolicyAssignmentService::class);
            $assignments->assign($s, $subjectEmployment->id, $casualPolicy->id, '2025-04-01', null, $admin);
            $assignments->assign($s, $subjectEmployment->id, $earnedPolicy->id, '2026-04-01', null, $admin);
            app(LeaveLedgerService::class)->executeRun($s, $casual->id, $closedYear->id, $admin, $this->freshMfaProofFor($admin));
            $request = $this->submitLeave(['school' => $s, 'admin' => $admin, 'type' => $casual, 'employment' => $subjectEmployment], '2026-03-02', '2026-03-03');
            app(LeaveRequestService::class)->approve($s, $request->id, $admin);
            app(LeaveYearCloseService::class)->execute($s, $closedYear->id, $admin);
            app(LeaveRequestService::class)->cancel($s, $request->id, 'plans_changed', $admin);
            app(LeaveAccrualService::class)->execute($s, $earned->id, $currentYear->id, '2026-05-01', $admin);
            $this->staffHoliday($s, '2026-08-17', DayPortion::Full, 'Founders Day', $admin);

            // Staff Attendance: a record and a correction in an open month, a locked month and its assured correction.
            $attendance = app(StaffAttendanceService::class);
            $may = $attendance->record($s, $subjectEmployment->id, '2026-05-11', 'present', 'present', $clerk);
            $june = $attendance->record($s, $subjectEmployment->id, '2026-06-01', 'present', 'present', $clerk);
            $attendance->correct($s, $june->id, $june->version, 'absent', 'present', 'entered_in_error', $clerk);
            $attendance->lockMonth($s, '2026-05', $locker, $this->freshMfaProofFor($locker));
            $locked = $attendance->requestLockedCorrection($s, $may->id, $may->version, 'present', 'absent', 'late_information', (string) Str::uuid(), $clerk);
            $attendance->approveLockedCorrection($s, $locked->id, $reviewer, $this->freshMfaProofFor($reviewer));
        });
    }
}
