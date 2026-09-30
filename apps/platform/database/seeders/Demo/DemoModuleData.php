<?php

namespace Database\Seeders\Demo;

use App\Domain\Admissions\Application\AdmissionApplicationService;
use App\Domain\Admissions\Application\ApplicantService;
use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Canteen\Application\CanteenBillingConfigurationService;
use App\Domain\Canteen\Application\CanteenItemService;
use App\Domain\Canteen\Application\CanteenOrderLineData;
use App\Domain\Canteen\Application\CanteenOrderService;
use App\Domain\Canteen\Application\CanteenOutletService;
use App\Domain\Canteen\Application\CanteenRecipeService;
use App\Domain\Canteen\Application\PlaceCanteenOrderData;
use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\CommunicationMessageService;
use App\Domain\Communications\Application\CommunicationTemplateService;
use App\Domain\Communications\Application\CommunicationThreadService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\CurriculumDelivery\Application\CurriculumDeliveryService;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\Examinations\Application\ExaminationPaperService;
use App\Domain\Examinations\Application\ExaminationService;
use App\Domain\Examinations\Application\GradeScaleService;
use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Fees\Application\FeeSettingsService;
use App\Domain\Finance\Application\JournalLineData;
use App\Domain\Finance\Application\LedgerService;
use App\Domain\Finance\Application\PostJournalEntryData;
use App\Domain\Finance\Domain\JournalSide;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Hostel\Application\HostelResidencyService;
use App\Domain\Hostel\Infrastructure\Hostel;
use App\Domain\Hostel\Infrastructure\HostelBed;
use App\Domain\Hostel\Infrastructure\HostelRoom;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\Inventory\Application\InventoryStockService;
use App\Domain\Inventory\Infrastructure\InventoryItem;
use App\Domain\Inventory\Infrastructure\InventoryLocation;
use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryTitle;
use App\Domain\Payments\Application\ChargeAllocationInput;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Domain\Payments\Application\NormalizedProviderEvent;
use App\Domain\Payments\Application\PaymentProviderEventService;
use App\Domain\Payments\Application\RecordManualPaymentData;
use App\Domain\Payments\Application\RecordSettlementData;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payroll\Application\AddStructureComponentData;
use App\Domain\Payroll\Application\CompensationService;
use App\Domain\Payroll\Application\FixedComponentValueInput;
use App\Domain\Payroll\Application\PayrollAccountingConfigurationService;
use App\Domain\Payroll\Application\PayrollPeriodService;
use App\Domain\Payroll\Application\PayrollPostingService;
use App\Domain\Payroll\Application\PayrollRunService;
use App\Domain\Payroll\Application\SalaryComponentService;
use App\Domain\Payroll\Application\SalaryStructureService;
use App\Domain\Students\Application\EnrollmentRolloverPlanService;
use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Domain\TeachingAssignments\Application\TeachingAssignmentService;
use App\Domain\Timetable\Application\TimetablePeriodService;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Domain\Transport\Application\TransportRouteAssignmentService;
use App\Domain\Transport\Application\TransportStudentAssignmentService;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStop;
use App\Domain\Transport\Infrastructure\TransportVehicle;
use App\Domain\Visitor\Application\VisitorVisitService;
use App\Domain\Visitor\Infrastructure\Visitor;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Module-level demo records for every published module with a
 * reviewable screen. Every lifecycle-bearing record goes through its
 * owning Application service (so audit rows, domain events, database
 * invariants and ledger postings are the real ones); plain reference
 * rows with no service (catalogue titles, stops, rooms, ...) are created
 * inside TenantContext exactly like the test fixtures do.
 */
final class DemoModuleData
{
    /** Past weekdays (Mon-Fri) before the dataset's reference date, 2026-09-22. */
    private const ATTENDANCE_DATES = ['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-21'];

    /** @var array<string, LedgerAccount> */
    private array $ledger = [];

    /** @var array<string, TimetableEntry> keyed "G6-A|1|P1" */
    private array $entries = [];

    public function __construct(private readonly DemoDataBuilder $demo) {}

    public function build(): void
    {
        $this->timetableAndAttendance();
        $this->syllabusAndDelivery();
        $this->teachingAssignments();
        $this->examinations();
        $this->communications();
        $this->finance();
        $this->payroll();
        $this->library();
        $this->transport();
        $this->visitors();
        $this->hostel();
        $this->inventoryAndCanteen();
        $this->admissions();
        $this->rollover();
    }

    // --- Timetable (0H.1) + Attendance (0H.2) ----------------------------

    private function timetableAndAttendance(): void
    {
        $d = $this->demo;
        $periods = [];
        $periodService = app(TimetablePeriodService::class);

        foreach ([
            'P1' => ['08:30', '09:15'], 'P2' => ['09:15', '10:00'], 'P3' => ['10:15', '11:00'],
            'P4' => ['11:00', '11:45'], 'P5' => ['12:15', '13:00'],
        ] as $code => [$start, $end]) {
            $periods[$code] = $d->inSchool(fn () => $periodService->create($d->school, [
                'code' => $code,
                'name' => 'Period '.substr($code, 1),
                'start_time' => $start,
                'end_time' => $end,
                'sort_order' => (int) substr($code, 1),
            ], $d->admin));
        }

        // Rotating grid over six core subjects (one teacher each) and six
        // Sections: at any (day, period) every Section studies a
        // different subject, so no teacher is ever double-booked.
        $subjects = ['ENG', 'HIN', 'MATH', 'SCI', 'SST', 'COMP'];
        $rooms = array_values($d->rooms);
        $schedule = app(TimetableScheduleService::class);
        $sectionIndex = 0;

        foreach ($d->sections as $sectionKey => $section) {
            $gradeKey = substr($sectionKey, 0, 2);
            $room = $rooms[$sectionIndex % 6];

            for ($day = 1; $day <= 5; $day++) {
                $p = 0;
                foreach ($periods as $periodCode => $period) {
                    $subjectCode = $subjects[($p + $sectionIndex + $day) % 6];
                    $this->entries["{$sectionKey}|{$day}|{$periodCode}"] = $schedule->create(
                        $d->school,
                        $d->offerings["{$gradeKey}-{$subjectCode}"],
                        $section,
                        $d->employees[$subjectCode],
                        $room,
                        $period,
                        $day,
                        $d->admin,
                    );
                    $p++;
                }
            }
            $sectionIndex++;
        }

        // Submitted first-period registers for two Sections over the
        // past week (a few absences / late arrivals for realism).
        $attendance = app(AttendanceSubmissionService::class);
        foreach (['G6-A', 'G7-A'] as $sectionKey) {
            foreach (self::ATTENDANCE_DATES as $dateIndex => $date) {
                $day = (int) Carbon::parse($date)->isoWeekday();
                $entry = $this->entries["{$sectionKey}|{$day}|P1"];
                $section = $d->sections[$sectionKey];

                $d->inSchool(function () use ($attendance, $entry, $date, $section, $dateIndex, $d) {
                    $records = [];
                    $enrollments = DB::table('student_enrollments')
                        ->where('section_id', $section->id)
                        ->where('status', 'active')
                        ->orderBy('roll_number')
                        ->pluck('id');
                    foreach ($enrollments as $i => $enrollmentId) {
                        $status = match (true) {
                            ($i + $dateIndex) % 11 === 3 => 'absent',
                            ($i + $dateIndex) % 13 === 5 => 'late',
                            default => 'present',
                        };
                        $records[] = ['student_enrollment_id' => $enrollmentId, 'status' => $status];
                    }

                    $attendance->guarded(fn () => DB::transaction(
                        fn () => $attendance->submit($d->school, $entry->id, $date, $records, $d->admin),
                    ));
                });
            }
        }
    }

    // --- Syllabus (0H.3A) + Curriculum Delivery (0H.3B) -------------------

    private function syllabusAndDelivery(): void
    {
        $d = $this->demo;
        $units = [
            'MATH' => ['Integers', 'Fractions and Decimals', 'Simple Equations', 'Lines and Angles', 'Data Handling'],
            'SCI' => ['Nutrition in Plants', 'Heat', 'Acids, Bases and Salts', 'Motion and Time', 'Electric Current'],
            'ENG' => ['Reading Comprehension', 'Grammar: Tenses', 'Letter Writing', 'Poetry', 'Short Stories'],
        ];

        $delivery = app(CurriculumDeliveryService::class);

        foreach ($d->gradeLevels as $gradeKey => $grade) {
            foreach ($units as $subjectCode => $titles) {
                $offering = $d->offerings["{$gradeKey}-{$subjectCode}"];
                $created = [];
                foreach ($titles as $i => $title) {
                    $created[] = $d->inSchool(fn () => SyllabusUnit::query()->create([
                        'school_id' => $d->school->id,
                        'subject_offering_id' => $offering->id,
                        'code' => $subjectCode.'-U'.($i + 1),
                        'title' => $title,
                        'sequence' => $i + 1,
                        'status' => 'active',
                    ]));
                }

                // Section A: first two units completed, third in progress.
                $section = $d->sections["{$gradeKey}-A"];
                foreach (array_slice($created, 0, 3) as $i => $unit) {
                    $started = $delivery->start($d->school, $offering->id, $section->id, $unit->id, Carbon::parse('2026-04-06')->addWeeks($i * 4)->toDateString(), $d->admin);
                    if ($i < 2) {
                        $delivery->transition(
                            $d->school,
                            $started->id,
                            CurriculumDelivery::STATUS_IN_PROGRESS,
                            CurriculumDelivery::STATUS_COMPLETED,
                            Carbon::parse('2026-04-06')->addWeeks($i * 4 + 3)->toDateString(),
                            $d->admin,
                        );
                    }
                }

                // Section B (Phase 0L.2-1, so the Curriculum Coverage
                // Analytics report shows real variation): MATH ahead
                // (4 completed), SCI behind (1 completed, 1 in
                // progress), ENG not started.
                [$completedB, $inProgressB] = ['MATH' => [4, 0], 'SCI' => [1, 1], 'ENG' => [0, 0]][$subjectCode];
                $sectionB = $d->sections["{$gradeKey}-B"];
                foreach (array_slice($created, 0, $completedB + $inProgressB) as $i => $unit) {
                    $started = $delivery->start($d->school, $offering->id, $sectionB->id, $unit->id, Carbon::parse('2026-04-13')->addWeeks($i * 4)->toDateString(), $d->admin);
                    if ($i < $completedB) {
                        $delivery->transition(
                            $d->school,
                            $started->id,
                            CurriculumDelivery::STATUS_IN_PROGRESS,
                            CurriculumDelivery::STATUS_COMPLETED,
                            Carbon::parse('2026-04-13')->addWeeks($i * 4 + 3)->toDateString(),
                            $d->admin,
                        );
                    }
                }
            }
        }
    }

    // --- Teaching Assignments (TCH.2/TCH.3) --------------------------------

    /**
     * The demo teacher (Kavya Reddy, MATH Employee) owns G8-A Mathematics
     * for the whole current year -- through the real service, so the demo
     * exercises ADR 0063's ownership check. Her timetable rows in other
     * classes grant nothing: only this assignment does.
     */
    private function teachingAssignments(): void
    {
        $d = $this->demo;

        app(TeachingAssignmentService::class)->create(
            $d->school,
            $d->employees['MATH']->id,
            $d->sections['G8-A']->id,
            $d->offerings['G8-MATH']->id,
            $d->currentYear->starts_on->toDateString(),
            null,
            $d->admin,
        );
    }

    // --- Examinations (0H.4A/B) + Grade scale (0H.4C) --------------------

    private function examinations(): void
    {
        $d = $this->demo;
        $exams = app(ExaminationService::class);
        $papers = app(ExaminationPaperService::class);

        $unitTest = $exams->create($d->school, $d->currentYear, ['code' => 'UT1', 'name' => 'Unit Test 1', 'starts_on' => '2026-07-13', 'ends_on' => '2026-07-17'], $d->admin);
        $termExam = $exams->create($d->school, $d->currentYear, ['code' => 'T1EXAM', 'name' => 'Term 1 Examination', 'starts_on' => '2026-09-28', 'ends_on' => '2026-10-06'], $d->admin);

        foreach (['ENG', 'HIN', 'MATH', 'SCI', 'SST'] as $i => $subjectCode) {
            foreach ($d->gradeLevels as $gradeKey => $grade) {
                $offeringId = $d->offerings["{$gradeKey}-{$subjectCode}"]->id;

                $papers->create($d->school, $unitTest, [
                    'subject_offering_id' => $offeringId,
                    'scheduled_on' => Carbon::parse('2026-07-13')->addDays($i)->toDateString(),
                    'starts_at' => '09:00',
                    'ends_at' => '10:00',
                    'max_marks' => '25.00',
                ], $d->admin);

                $papers->create($d->school, $termExam, [
                    'subject_offering_id' => $offeringId,
                    'scheduled_on' => Carbon::parse('2026-09-28')->addWeekdays($i)->toDateString(),
                    'starts_at' => '09:30',
                    'ends_at' => '12:30',
                    'max_marks' => '80.00',
                ], $d->admin);
            }
        }

        $grades = app(GradeScaleService::class);
        $scale = $grades->create($d->school, [
            'code' => 'CBSE8',
            'name' => 'Eight-point scale (demo)',
            'bands' => [
                ['min_percentage' => '91.00', 'label' => 'A1'],
                ['min_percentage' => '81.00', 'label' => 'A2'],
                ['min_percentage' => '71.00', 'label' => 'B1'],
                ['min_percentage' => '61.00', 'label' => 'B2'],
                ['min_percentage' => '51.00', 'label' => 'C1'],
                ['min_percentage' => '41.00', 'label' => 'C2'],
                ['min_percentage' => '33.00', 'label' => 'D'],
                ['min_percentage' => '0.00', 'label' => 'E'],
            ],
        ], $d->admin);
        $grades->update($d->school, $scale, ['status' => 'active'], $d->admin);

        $grades->create($d->school, [
            'code' => 'PASSFAIL',
            'name' => 'Pass / Fail (draft)',
            'bands' => [
                ['min_percentage' => '33.00', 'label' => 'Pass'],
                ['min_percentage' => '0.00', 'label' => 'Fail'],
            ],
        ], $d->admin);
    }

    // --- Communications (Phase 5A-5D) -------------------------------------

    private function communications(): void
    {
        $d = $this->demo;
        $principal = User::query()->where('email', 'principal@example.test')->firstOrFail();
        $guardianUser = User::query()->where('email', 'guardian01@example.test')->firstOrFail();

        $templates = app(CommunicationTemplateService::class);
        $templates->create($d->school, $d->admin, 'Fee reminder', 'Dear parent, this is a reminder that the Term 1 fee is due. Please contact the school office with any questions.', 'Standard fee reminder', 'Fee reminder', CommunicationPriority::Important);
        $templates->create($d->school, $d->admin, 'Parent-teacher meeting', 'Dear parent, the parent-teacher meeting will be held on Saturday from 9:00 to 12:00 in the school auditorium.', 'PTM invitation', 'Parent-teacher meeting');

        $announcements = app(AnnouncementService::class);

        $welcome = $announcements->createDraft(
            $d->school, $d->admin,
            'Welcome to the 2026-27 academic year',
            'Classes for 2026-27 have begun. The Term 1 examination timetable is now available from the class teacher.',
            CommunicationPriority::Normal,
            CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp],
        );
        $announcements->publish($welcome, $d->admin);

        $grade8Guardians = array_map(fn ($s) => $s->id, array_merge($d->studentsBySection['G8-A'], $d->studentsBySection['G8-B']));
        $ptm = $announcements->createDraft(
            $d->school, $d->admin,
            'Grade 8 parent-teacher meeting',
            'Parents of Grade 8 students are invited to the parent-teacher meeting on Saturday, 26 September 2026.',
            CommunicationPriority::Important,
            CommunicationAudienceType::GuardiansOfStudents,
            channels: [CommunicationChannel::InApp],
            domainAudienceMemberIds: $grade8Guardians,
        );
        $announcements->publish($ptm, $d->admin);

        $announcements->createDraft(
            $d->school, $d->admin,
            'Sports day (draft)',
            'Draft: annual sports day planning notice -- not yet published.',
            CommunicationPriority::Normal,
            CommunicationAudienceType::SchoolWide,
            channels: [CommunicationChannel::InApp],
        );

        $threads = app(CommunicationThreadService::class);
        $messages = app(CommunicationMessageService::class);

        $staffThread = $threads->createThread($d->school, $d->admin, 'direct', 'Term 1 examination planning', [$principal->id]);
        $messages->send($staffThread, $d->admin, 'Could you review the Term 1 paper schedule before Friday?');
        $messages->send($staffThread, $principal, 'Reviewed -- the Grade 8 Science paper moves to the afternoon slot.');

        $guardianThread = $threads->createThread($d->school, $d->admin, 'direct', 'Aarav -- homework follow-up', [], null, [$d->guardians[0]->id]);
        $messages->send($guardianThread, $d->admin, 'Hello, Aarav has missed two mathematics assignments this month. Could we discuss?');
        $messages->send($guardianThread, $guardianUser, 'Thank you for letting me know. I will make sure he catches up this week.');
    }

    // --- Finance ledger (0G) + Fees + Payments ----------------------------

    private function finance(): void
    {
        $d = $this->demo;

        foreach ([
            'BANK' => ['1000', 'Bank -- Current Account', 'asset'],
            'CASH' => ['1010', 'Cash in Hand', 'asset'],
            'FEES_AR' => ['1100', 'Fees Receivable', 'asset'],
            'CANTEEN_AR' => ['1200', 'Canteen Receivable', 'asset'],
            'SAL_PAYABLE' => ['2100', 'Salaries Payable', 'liability'],
            'PF_PAYABLE' => ['2200', 'Provident Fund Payable', 'liability'],
            'EQUITY' => ['3000', 'Opening Balance Equity', 'equity'],
            'TUITION' => ['4000', 'Tuition Fee Income', 'income'],
            'TRANSPORT_INC' => ['4100', 'Transport Fee Income', 'income'],
            'CANTEEN_INC' => ['4200', 'Canteen Income', 'income'],
            'SALARY_EXP' => ['5000', 'Salary Expense', 'expense'],
            'STATIONERY' => ['5100', 'Stationery Expense', 'expense'],
            // FEE.3 (ADR 0062 §14.5, F2): the School's concession account.
            'CONCESSION_EXP' => ['5200', 'Fee Concessions and Scholarships', 'expense'],
        ] as $key => [$code, $name, $type]) {
            $this->ledger[$key] = $d->inSchool(fn () => LedgerAccount::query()->create([
                'school_id' => $d->school->id,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'currency' => 'INR',
                'is_system' => false,
                'status' => 'active',
            ]));
        }

        app(FeeSettingsService::class)->setConcessionAccount($d->school, $this->ledger['CONCESSION_EXP']->id, $d->admin);

        $ledger = app(LedgerService::class);
        $ledger->post($d->school, new PostJournalEntryData(
            currency: 'INR',
            description: 'Opening bank balance (demo)',
            lines: [
                new JournalLineData($this->ledger['BANK']->id, JournalSide::Debit, Money::of('500000.00', 'INR')),
                new JournalLineData($this->ledger['EQUITY']->id, JournalSide::Credit, Money::of('500000.00', 'INR')),
            ],
        ), $d->admin);
        $ledger->post($d->school, new PostJournalEntryData(
            currency: 'INR',
            description: 'Stationery purchase for Term 1 (demo)',
            lines: [
                new JournalLineData($this->ledger['STATIONERY']->id, JournalSide::Debit, Money::of('12500.00', 'INR')),
                new JournalLineData($this->ledger['BANK']->id, JournalSide::Credit, Money::of('12500.00', 'INR')),
            ],
        ), $d->admin);

        $charges = app(ChargeService::class);
        $payments = app(PaymentProviderEventService::class);
        $offline = app(ManualPaymentRecordingService::class);
        $methods = [ManualPaymentMethod::Cash, ManualPaymentMethod::BankTransfer, ManualPaymentMethod::Cheque];

        foreach ($d->students as $i => $student) {
            $result = $charges->assess($d->school, new AssessChargeData(
                studentId: $student->id,
                academicYearId: $d->currentYear->id,
                description: 'Term 1 tuition fee 2026-27',
                amount: Money::of('25000.00', 'INR'),
                receivableLedgerAccountId: $this->ledger['FEES_AR']->id,
                revenueLedgerAccountId: $this->ledger['TUITION']->id,
                dueDate: '2026-04-30',
            ), $d->admin);

            // Two thirds paid in full, a few part-paid, the rest outstanding.
            $paid = match ($i % 6) {
                0, 1, 2, 3 => '25000.00',
                4 => '10000.00',
                default => null,
            };

            if ($paid === null) {
                continue;
            }

            $occurredAt = Carbon::parse('2026-04-10')->addDays($i);
            $allocations = [new ChargeAllocationInput($result->chargeId, Money::of($paid, 'INR'))];

            // Phase 0O.11A: most demo fees were paid offline, recorded by
            // the School Admin through the real manual recording service
            // (cash into Cash in Hand, bank transfer/cheque into Bank). A
            // few stay provider-derived so the two sources can be compared
            // -- 'demo-provider' is demo data only, never a real gateway.
            if (in_array($i % 6, [2, 3], true) && $i < 12) {
                $payments->recordSettlement($d->school, new RecordSettlementData(
                    event: new NormalizedProviderEvent(
                        provider: 'demo-provider',
                        providerEventId: sprintf('demo-evt-%04d', $i + 1),
                        providerPaymentReference: sprintf('DEMO-PSP-%04d', $i + 1),
                        eventType: 'payment.settled',
                        amount: Money::of($paid, 'INR'),
                        occurredAt: $occurredAt,
                    ),
                    settlementLedgerAccountId: $this->ledger['BANK']->id,
                    allocations: $allocations,
                ), $d->admin);

                continue;
            }

            $method = $methods[$i % 3];
            $offline->record($d->school, new RecordManualPaymentData(
                method: $method,
                amount: Money::of($paid, 'INR'),
                occurredOn: $occurredAt->toDateString(),
                reference: match ($method) {
                    ManualPaymentMethod::Cash => null,
                    ManualPaymentMethod::BankTransfer => sprintf('DEMOUTR%08d', $i + 1),
                    ManualPaymentMethod::Cheque => sprintf('%06d', 400100 + $i),
                },
                settlementLedgerAccountId: $this->ledger[$method === ManualPaymentMethod::Cash ? 'CASH' : 'BANK']->id,
                allocations: $allocations,
                idempotencyKey: (string) Str::uuid(),
            ), $d->admin);
        }
    }

    // --- Payroll (Phase 9) --------------------------------------------------

    private function payroll(): void
    {
        $d = $this->demo;
        $preparer = User::query()->where('email', 'hr.payroll@example.test')->firstOrFail();

        app(PayrollAccountingConfigurationService::class)->configure($d->school, $this->ledger['SALARY_EXP']->id, $this->ledger['SAL_PAYABLE']->id, $preparer);

        $componentService = app(SalaryComponentService::class);
        $structureService = app(SalaryStructureService::class);

        $basic = $componentService->create($d->school, 'BASIC', 'Basic Pay', 'earning', null, $preparer);
        $hra = $componentService->create($d->school, 'HRA', 'House Rent Allowance', 'earning', null, $preparer);
        $pf = $componentService->create($d->school, 'PF', 'Provident Fund (employee)', 'deduction', $this->ledger['PF_PAYABLE']->id, $preparer);

        $structure = $structureService->createDraft($d->school, 'STAFF-STD', 'Standard Staff Structure', $preparer);
        $basicSc = $structureService->addComponent($structure, new AddStructureComponentData($basic->id, 'fixed_amount', null, null, 1), $preparer);
        $hraSc = $structureService->addComponent($structure, new AddStructureComponentData($hra->id, 'fixed_amount', null, null, 2), $preparer);
        $pfSc = $structureService->addComponent($structure, new AddStructureComponentData($pf->id, 'fixed_amount', null, null, 3), $preparer);
        $structure = $structureService->activate($structure, $preparer);

        $salaries = [
            'ENG' => '52000.00', 'HIN' => '41000.00', 'MATH' => '55000.00', 'SCI' => '54000.00',
            'SST' => '42000.00', 'ACCT' => '38000.00', 'LIB' => '30000.00', 'DRV' => '22000.00',
        ];
        $compensation = app(CompensationService::class);
        foreach ($salaries as $employeeKey => $basicAmount) {
            $record = $d->inSchool(fn () => EmploymentRecord::query()->where('employee_id', $d->employees[$employeeKey]->id)->firstOrFail());
            $hraAmount = bcmul($basicAmount, '0.40', 2);
            // Employee PF at 12% of the Rs 15,000 wage ceiling (fixed demo value).
            $pfAmount = '1800.00';
            $compensation->assign($d->school, $record, $structure, Carbon::parse('2026-04-01'), [
                new FixedComponentValueInput($basicSc->id, $basicAmount),
                new FixedComponentValueInput($hraSc->id, $hraAmount),
                new FixedComponentValueInput($pfSc->id, $pfAmount),
            ], $preparer);
        }

        $periods = app(PayrollPeriodService::class);
        $runs = app(PayrollRunService::class);

        // August 2026: prepared by HR & Payroll, approved by the School
        // Admin (a different user -- separation of duties), then posted.
        $august = $periods->open($periods->createPeriod($d->school, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'), $preparer), $preparer);
        $augustRun = $runs->createRun($august, $preparer);
        $runs->calculate($augustRun, $preparer);
        $augustRun = $runs->approve($d->inSchool(fn () => $augustRun->fresh()), $d->admin);
        app(PayrollPostingService::class)->post($augustRun, $preparer);

        // September 2026: calculated, awaiting approval.
        $september = $periods->open($periods->createPeriod($d->school, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'), $preparer), $preparer);
        $septemberRun = $runs->createRun($september, $preparer);
        $runs->calculate($septemberRun, $preparer);
    }

    // --- Library (10A) ------------------------------------------------------

    private function library(): void
    {
        $d = $this->demo;
        $loans = app(LibraryLoanService::class);
        $books = [
            ['Wings of Fire', 'A. P. J. Abdul Kalam'], ['Malgudi Days', 'R. K. Narayan'],
            ['The Jungle Book', 'Rudyard Kipling'], ['Swami and Friends', 'R. K. Narayan'],
            ['Panchatantra Tales', 'Vishnu Sharma (retold)'], ['NCERT Mathematics Class 7', 'NCERT'],
        ];
        $copyNumber = 1;
        $copies = [];
        foreach ($books as [$title, $author]) {
            $libraryTitle = $d->inSchool(fn () => LibraryTitle::query()->create([
                'school_id' => $d->school->id, 'title' => $title, 'author' => $author, 'isbn' => null, 'status' => 'active',
            ]));
            for ($c = 0; $c < 2; $c++) {
                $code = sprintf('LIB-%05d', $copyNumber++);
                $copies[] = $d->inSchool(fn () => LibraryCopy::query()->create([
                    'school_id' => $d->school->id,
                    'library_title_id' => $libraryTitle->id,
                    'campus_id' => $d->campus->id,
                    'code' => $code,
                    'status' => 'active',
                ]));
            }
        }

        foreach (array_slice($copies, 0, 5) as $i => $copy) {
            $loan = $d->inSchool(fn () => $loans->checkout($copy, $d->students[$i * 3], Carbon::now()->addDays(14 - $i * 2), $d->admin));
            if ($i === 4) {
                $d->inSchool(fn () => $loans->checkIn($loan, $d->admin));
            }
        }
    }

    // --- Transport (10B) ----------------------------------------------------

    private function transport(): void
    {
        $d = $this->demo;
        $d->inSchool(function () use ($d) {
            $route = TransportRoute::query()->create([
                'school_id' => $d->school->id, 'campus_id' => $d->campus->id, 'code' => 'RT-NORTH',
                'name' => 'North Route', 'description' => 'Hebbal - Yelahanka loop', 'status' => 'active',
            ]);
            $stops = [];
            foreach (['Hebbal Flyover', 'Sahakar Nagar', 'Yelahanka New Town'] as $i => $name) {
                $stops[] = TransportStop::query()->create([
                    'school_id' => $d->school->id, 'route_id' => $route->id, 'name' => $name,
                    'sequence' => $i + 1, 'address' => $name.', Bengaluru', 'status' => 'active',
                ]);
            }
            $vehicle = TransportVehicle::query()->create([
                'school_id' => $d->school->id, 'campus_id' => $d->campus->id, 'code' => 'BUS-01',
                'registration_number' => 'KA-01-DM-0001', 'capacity' => 40, 'status' => 'active',
            ]);
            TransportVehicle::query()->create([
                'school_id' => $d->school->id, 'campus_id' => $d->campus->id, 'code' => 'BUS-02',
                'registration_number' => 'KA-01-DM-0002', 'capacity' => 32, 'status' => 'active',
            ]);

            app(TransportRouteAssignmentService::class)->assign($route, $vehicle, $d->employees['DRV'], $d->admin);

            $students = app(TransportStudentAssignmentService::class);
            foreach (array_slice($d->students, 0, 8) as $i => $student) {
                $students->assign($student, $route, $stops[$i % 3], $stops[$i % 3], $d->admin);
            }
        });
    }

    // --- Visitor (10C) ------------------------------------------------------

    private function visitors(): void
    {
        $d = $this->demo;
        $service = app(VisitorVisitService::class);
        $d->inSchool(function () use ($d, $service) {
            foreach ([['Anand Kumar', 'Parent meeting'], ['Sita Ram Vendors', 'Stationery delivery'], ['Dr. Neha Kapoor', 'Health check-up camp']] as $i => [$name, $purpose]) {
                $visitor = Visitor::query()->create([
                    'school_id' => $d->school->id, 'full_name' => $name,
                    'phone' => sprintf('90000%05d', $i + 1), 'status' => 'active',
                ]);
                $visit = $service->checkIn($visitor, $d->campus, $i === 0 ? $d->employees['ENG'] : null, $purpose, sprintf('GP-%04d', $i + 1), $d->admin);
                if ($i < 2) {
                    $service->checkOut($visit, $d->admin);
                }
            }
        });
    }

    // --- Hostel (10D) -------------------------------------------------------

    private function hostel(): void
    {
        $d = $this->demo;
        $d->inSchool(function () use ($d) {
            $hostel = Hostel::query()->create([
                'school_id' => $d->school->id, 'campus_id' => $d->campus->id, 'code' => 'HOSTEL-A', 'name' => 'Aravali Hostel', 'status' => 'active',
            ]);
            $beds = [];
            foreach (['R-101', 'R-102'] as $roomCode) {
                $room = HostelRoom::query()->create([
                    'school_id' => $d->school->id, 'hostel_id' => $hostel->id, 'code' => $roomCode, 'floor_or_block' => 'Ground floor', 'status' => 'active',
                ]);
                foreach (['B-1', 'B-2', 'B-3'] as $bedCode) {
                    $beds[] = HostelBed::query()->create([
                        'school_id' => $d->school->id, 'hostel_room_id' => $room->id, 'code' => $bedCode, 'status' => 'active',
                    ]);
                }
            }

            $residency = app(HostelResidencyService::class);
            foreach (array_slice($d->studentsBySection['G8-B'], 0, 4) as $i => $student) {
                $residency->assign($student, $beds[$i], $d->admin);
            }
        });
    }

    // --- Inventory (10E) + Canteen (10F) ------------------------------------

    private function inventoryAndCanteen(): void
    {
        $d = $this->demo;
        $stock = app(InventoryStockService::class);

        [$store, $kitchen, $items] = $d->inSchool(function () use ($d) {
            $store = InventoryLocation::query()->create(['school_id' => $d->school->id, 'campus_id' => $d->campus->id, 'code' => 'MAIN-STORE', 'name' => 'Main Store', 'status' => 'active']);
            $kitchen = InventoryLocation::query()->create(['school_id' => $d->school->id, 'campus_id' => $d->campus->id, 'code' => 'KITCHEN', 'name' => 'Canteen Kitchen Store', 'status' => 'active']);
            $items = [];
            foreach ([
                'NOTEBOOK' => ['Ruled notebook (A4)', 'each'], 'CHALK' => ['Chalk box', 'box'],
                'MILK' => ['Milk', 'litre'], 'BREAD' => ['Bread slice', 'each'], 'RICE' => ['Rice', 'kg'],
            ] as $code => [$name, $unit]) {
                $items[$code] = InventoryItem::query()->create(['school_id' => $d->school->id, 'code' => $code, 'name' => $name, 'unit_of_measure' => $unit, 'status' => 'active']);
            }

            return [$store, $kitchen, $items];
        });

        $d->inSchool(function () use ($stock, $store, $kitchen, $items, $d) {
            $stock->receive($items['NOTEBOOK'], $store, '500', $d->admin);
            $stock->receive($items['CHALK'], $store, '40', $d->admin);
            $stock->issue($items['NOTEBOOK'], $store, '120', $d->admin);
            $stock->receive($items['MILK'], $kitchen, '60', $d->admin);
            $stock->receive($items['BREAD'], $kitchen, '200', $d->admin);
            $stock->receive($items['RICE'], $store, '100', $d->admin);
            $stock->transfer($items['RICE'], $store, $kitchen, '25', $d->admin);
        });

        $d->inSchool(fn () => app(CanteenBillingConfigurationService::class)->configure($d->school, $this->ledger['CANTEEN_AR']->id, $this->ledger['CANTEEN_INC']->id, $d->admin));

        [$outlet, $menu] = $d->inSchool(function () use ($d, $kitchen, $items) {
            $outlet = app(CanteenOutletService::class)->create($d->school, [
                'campus_id' => $d->campus->id, 'inventory_location_id' => $kitchen->id, 'code' => 'MAIN-CANTEEN', 'name' => 'Main Canteen',
            ], $d->admin);
            $itemService = app(CanteenItemService::class);
            $menu = [
                'MILK' => $itemService->create($d->school, ['code' => 'MILK-250', 'name' => 'Flavoured milk (250 ml)', 'price' => '30.00'], $d->admin),
                'SANDWICH' => $itemService->create($d->school, ['code' => 'SANDWICH', 'name' => 'Vegetable sandwich', 'price' => '45.00'], $d->admin),
                'MEALS' => $itemService->create($d->school, ['code' => 'MEALS', 'name' => 'Lunch meal', 'price' => '80.00'], $d->admin),
            ];
            $recipes = app(CanteenRecipeService::class);
            $recipes->setRequirement($d->school, $menu['MILK'], $items['MILK'], '0.25', $d->admin);
            $recipes->setRequirement($d->school, $menu['SANDWICH'], $items['BREAD'], '2', $d->admin);

            return [$outlet, $menu];
        });

        $orders = app(CanteenOrderService::class);
        $d->inSchool(function () use ($d, $orders, $outlet, $menu) {
            foreach (array_slice($d->students, 0, 5) as $i => $student) {
                $result = $orders->place($d->school, new PlaceCanteenOrderData(
                    studentId: $student->id,
                    outletId: $outlet->id,
                    lines: [
                        new CanteenOrderLineData($menu['MILK']->id, 1),
                        new CanteenOrderLineData($menu[$i % 2 === 0 ? 'SANDWICH' : 'MEALS']->id, 1),
                    ],
                ), $d->admin);

                // First three orders fulfilled (stock consumed via the recipe).
                if ($i < 3) {
                    $orders->fulfill($d->school, $result->orderId, $d->admin);
                }
            }
        });
    }

    // --- Admissions (1D) ----------------------------------------------------

    private function admissions(): void
    {
        $d = $this->demo;
        $applicants = app(ApplicantService::class);
        $applications = app(AdmissionApplicationService::class);

        foreach ([
            ['Aditi', 'Banerjee', '2015-02-11', 'G6', 'draft'],
            ['Farhan', 'Siddiqui', '2014-07-19', 'G7', 'submitted'],
            ['Gauri', 'Kulkarni', '2013-11-03', 'G8', 'accepted'],
            ['Harsh', 'Malhotra', '2015-05-27', 'G6', 'rejected'],
        ] as [$first, $last, $dob, $gradeKey, $state]) {
            $applicant = $applicants->create($d->school, ['first_name' => $first, 'last_name' => $last, 'date_of_birth' => $dob], $d->admin);
            $application = $d->inSchool(fn () => $applications->create($applicant, $d->currentYear, $d->campus, $d->gradeLevels[$gradeKey], $d->admin));

            if ($state === 'draft') {
                continue;
            }
            $application = $d->inSchool(fn () => $applications->submit($application, $d->admin));
            if ($state === 'accepted') {
                $d->inSchool(fn () => $applications->accept($application, 'Meets Grade 8 admission criteria.', $d->admin));
            } elseif ($state === 'rejected') {
                $d->inSchool(fn () => $applications->reject($application, 'Grade 6 is at capacity for 2026-27.', $d->admin));
            }
        }
    }

    // --- Enrollment rollover planning (1B.7) ----------------------------------

    private function rollover(): void
    {
        $d = $this->demo;
        app(EnrollmentRolloverPlanService::class)->createDraft($d->school, $d->currentYear, $d->nextYear, $d->admin);
    }
}
