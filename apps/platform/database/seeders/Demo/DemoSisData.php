<?php

namespace Database\Seeders\Demo;

use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Infrastructure\AcademicDepartment;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeCampusAttribution;
use App\Domain\Fees\Application\ChargeService;
use App\Domain\Finance\Infrastructure\LedgerAccount;
use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Application\GuardianService;
use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\HR\Application\EmployeeNumberAllocator;
use App\Domain\HR\Application\EmployeeNumberFormatter;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeeCategory;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Domain\Library\Application\LibraryLoanService;
use App\Domain\Library\Infrastructure\LibraryCopy;
use App\Domain\Library\Infrastructure\LibraryTitle;
use App\Domain\Payments\Application\ChargeAllocationInput;
use App\Domain\Payments\Application\ManualPaymentRecordingService;
use App\Domain\Payments\Application\RecordManualPaymentData;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Timetable\Application\TimetablePeriodService;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Transport\Application\TransportStudentAssignmentService;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStop;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Money\Money;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SIS.EXP-1: the canonical SIS demonstration on Northstar International
 * School — Gachibowli (the Network's owned, two-Campus School), added to the
 * ORG.12E-D1 Organization world -- never a separate SIS universe.
 *
 * - 2026-27 active; Grade 3 and Grade 5 (two Sections) on the Main Campus,
 *   UKG on the Early Years Campus; required subjects per Grade.
 * - 13 fictional Students, every one created and placed through the real
 *   services (StudentService allocates the numbers; StudentEnrollmentService
 *   places them), each with a Guardian and contacts (`@example.test`,
 *   non-assignable phone numbers).
 * - The Menon family: Aarav (Grade 5 · A, Main) with two Guardians, and
 *   his sister Diya (UKG · A, Early Years) sharing their mother -- the
 *   sibling block across both Campuses.
 * - Aarav's Student 360: a part-paid Charge, a rider assignment, a library
 *   loan, recent attendance registers (dated relative to the reset day, so
 *   the profile's 30-day window always has records) and his subjects.
 *   Hostel is enabled but holds no residency (an authorized, empty card).
 * - A Campus-scoped Principal for the Early Years Campus only (an existing
 *   role template on an existing Campus-grant pattern): the Campus
 *   directory and overview, never the whole-School profile.
 *
 * Built only through DemoDataBuilder's guard (local DDEV or the verified test
 * database). Verified after every reset by DemoSisVerifier.
 */
final class DemoSisData
{
    public const SCHOOL_SLUG = 'northstar-gachibowli';

    /** @var array<string, Student> */
    public array $students = [];

    /** @var array<string, Section> */
    private array $sections = [];

    /** @var array<string, SubjectOffering> */
    private array $offerings = [];

    private AcademicYear $year;

    private School $school;

    private User $admin;

    /** @param array<string, Campus> $campuses the Organization world's Campuses by key */
    public function __construct(private readonly DemoDataBuilder $b, private readonly array $campuses) {}

    public function build(School $school): void
    {
        $this->school = $school;
        $this->admin = User::query()->where('email', 'gachibowli.admin@example.test')->firstOrFail();

        $this->academicStructure();
        $this->studentsAndGuardians();
        $this->finance();
        $this->transportAndLibrary();
        $this->attendance();
        $this->campusPrincipal();

        $this->b->note('SIS demo (SIS.EXP-1): Northstar Gachibowli -- 13 Students on both Campuses; the Menon siblings (Aarav, Grade 5 · A, Main; Diya, UKG · A, Early Years) share a Guardian; Aarav shows every Student 360 card -- see docs/demo/SIS-EXP-1-WALKTHROUGH.md.');
    }

    private function academicStructure(): void
    {
        $years = app(AcademicYearService::class);
        $this->year = $years->activate($years->create($this->school, ['name' => '2026-27', 'code' => 'AY2026', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31'], $this->admin), $this->admin);

        $this->b->inSchool(function (): void {
            $main = $this->campuses['gachibowli_main'];
            $early = $this->campuses['gachibowli_early'];

            $grades = [];
            foreach (['UKG' => ['UKG', 2], 'G3' => ['Grade 3', 5], 'G5' => ['Grade 5', 7]] as $code => [$name, $sequence]) {
                $grades[$code] = GradeLevel::query()->create(['school_id' => $this->school->id, 'name' => $name, 'code' => $code, 'sequence' => $sequence, 'education_stage' => null, 'status' => 'active']);
            }
            $department = AcademicDepartment::query()->create(['school_id' => $this->school->id, 'name' => 'Primary', 'code' => 'PRI', 'status' => 'active']);
            $subjects = [];
            foreach (['ENG' => 'English', 'MATH' => 'Mathematics', 'EVS' => 'Environmental Studies'] as $code => $name) {
                $subjects[$code] = Subject::query()->create(['school_id' => $this->school->id, 'academic_department_id' => $department->id, 'name' => $name, 'code' => $code, 'short_name' => $code, 'subject_type' => 'core', 'status' => 'active']);
            }
            foreach (['G5-A' => ['G5', 'A', $main], 'G5-B' => ['G5', 'B', $main], 'G3-A' => ['G3', 'A', $main], 'UKG-A' => ['UKG', 'A', $early]] as $key => [$grade, $letter, $campus]) {
                $this->sections[$key] = Section::query()->create([
                    'school_id' => $this->school->id, 'academic_year_id' => $this->year->id, 'campus_id' => $campus->id,
                    'grade_level_id' => $grades[$grade]->id, 'name' => $letter, 'code' => $letter, 'capacity' => 30, 'status' => 'active',
                ]);
            }
            foreach (['G5' => $main, 'G3' => $main, 'UKG' => $early] as $grade => $campus) {
                foreach (['ENG', 'MATH', 'EVS'] as $sequence => $code) {
                    $this->offerings["{$grade}-{$code}"] = SubjectOffering::query()->create([
                        'school_id' => $this->school->id, 'academic_year_id' => $this->year->id, 'campus_id' => $campus->id,
                        'grade_level_id' => $grades[$grade]->id, 'subject_id' => $subjects[$code]->id, 'is_required' => true,
                        'sequence' => $sequence + 1, 'weekly_periods_target' => 5, 'status' => 'active',
                    ]);
                }
            }
        }, $this->school);
    }

    private function studentsAndGuardians(): void
    {
        $students = app(StudentService::class);
        $placements = app(StudentEnrollmentService::class);
        $guardians = app(GuardianService::class);
        $contacts = app(GuardianContactService::class);
        $links = app(StudentGuardianRelationshipService::class);

        $guardian = function (string $first, string $last, int $n) use ($guardians, $contacts): Guardian {
            $g = $guardians->create($this->school, ['first_name' => $first, 'last_name' => $last], $this->admin);
            $contacts->create($g, ContactType::Email, sprintf('gcb.guardian%02d@example.test', $n), ['is_primary' => true], $this->admin);
            $contacts->create($g, ContactType::Mobile, sprintf('+9198100%05d', $n), [], $this->admin);

            return $g;
        };
        $student = function (string $key, string $first, string $last, string $dob, string $section, string $roll) use ($students, $placements): Student {
            $s = $students->create($this->school, ['first_name' => $first, 'middle_name' => null, 'last_name' => $last, 'date_of_birth' => $dob], $this->admin);
            $placements->enroll($s, $this->sections[$section], $roll, '2026-06-01', $this->admin);

            return $this->students[$key] = $s;
        };

        // The Menon family: two Guardians for Aarav; Diya shares their mother.
        $aarav = $student('aarav', 'Aarav', 'Menon', '2015-08-14', 'G5-A', '1');
        $diya = $student('diya', 'Diya', 'Menon', '2021-02-03', 'UKG-A', '1');
        $mother = $guardian('Lakshmi', 'Menon', 1);
        $father = $guardian('Vikram', 'Menon', 2);
        $links->link($aarav, $mother, RelationshipType::Mother, ['is_primary' => true, 'is_legal_guardian' => true, 'is_emergency_contact' => true, 'is_authorized_pickup' => true], $this->admin);
        $links->link($aarav, $father, RelationshipType::Father, ['is_legal_guardian' => true, 'is_authorized_pickup' => true], $this->admin);
        $links->link($diya, $mother, RelationshipType::Mother, ['is_primary' => true, 'is_legal_guardian' => true, 'is_emergency_contact' => true, 'is_authorized_pickup' => true], $this->admin);

        // Classmates and the other Sections, one Guardian each.
        $others = [
            ['meera', 'Meera', 'Joshi', '2015-05-02', 'G5-A', '2', 'Anjali'],
            ['kabir', 'Kabir', 'Sethi', '2015-11-20', 'G5-A', '3', 'Rahul'],
            ['ananya', 'Ananya', 'Rao', '2015-03-09', 'G5-A', '4', 'Sunita'],
            ['ishaan', 'Ishaan', 'Das', '2015-07-27', 'G5-B', '1', 'Priya'],
            ['sara', 'Sara', 'Khan', '2015-12-11', 'G5-B', '2', 'Farah'],
            ['vivaan', 'Vivaan', 'Nair', '2015-01-30', 'G5-B', '3', 'Arjun'],
            ['riya', 'Riya', 'Bose', '2017-09-05', 'G3-A', '1', 'Tanya'],
            ['aditya', 'Aditya', 'Kulkarni', '2017-04-18', 'G3-A', '2', 'Sameer'],
            ['zara', 'Zara', 'Ali', '2017-10-22', 'G3-A', '3', 'Nazia'],
            ['neel', 'Neel', 'Shah', '2021-06-16', 'UKG-A', '2', 'Pooja'],
            ['tara', 'Tara', 'Iyer', '2021-01-08', 'UKG-A', '3', 'Kavitha'],
        ];
        foreach ($others as $i => [$key, $first, $last, $dob, $section, $roll, $guardianFirst]) {
            $s = $student($key, $first, $last, $dob, $section, $roll);
            $links->link($s, $guardian($guardianFirst, $last, $i + 3), $i % 2 === 0 ? RelationshipType::Mother : RelationshipType::Father, ['is_primary' => true, 'is_legal_guardian' => true, 'is_emergency_contact' => true], $this->admin);
        }
    }

    private function finance(): void
    {
        $ledger = [];
        foreach (['CASH' => ['1010', 'Cash in Hand', 'asset'], 'FEES_AR' => ['1100', 'Fees Receivable', 'asset'], 'TUITION' => ['4000', 'Tuition Fee Income', 'income']] as $key => [$code, $name, $type]) {
            $ledger[$key] = $this->b->inSchool(fn () => LedgerAccount::query()->create([
                'school_id' => $this->school->id, 'code' => $code, 'name' => $name, 'type' => $type, 'currency' => 'INR', 'is_system' => false, 'status' => 'active',
            ]), $this->school);
        }

        $charges = app(ChargeService::class);
        $assess = fn (Student $s, string $amount) => $charges->assess($this->school, new AssessChargeData(
            studentId: $s->id,
            academicYearId: $this->year->id,
            description: 'Term 1 tuition fee 2026-27',
            amount: Money::of($amount, 'INR'),
            receivableLedgerAccountId: $ledger['FEES_AR']->id,
            revenueLedgerAccountId: $ledger['TUITION']->id,
            campus: ChargeCampusAttribution::placementOnAssessmentDate(),
            dueDate: '2026-07-15',
        ), $this->admin);

        // Aarav: part-paid (the Fees card shows billed, paid and outstanding).
        $aarav = $assess($this->students['aarav'], '30000.00');
        app(ManualPaymentRecordingService::class)->record($this->school, new RecordManualPaymentData(
            method: ManualPaymentMethod::Cash,
            amount: Money::of('12000.00', 'INR'),
            occurredOn: '2026-06-20',
            reference: null,
            settlementLedgerAccountId: $ledger['CASH']->id,
            allocations: [new ChargeAllocationInput($aarav->chargeId, Money::of('12000.00', 'INR'))],
            idempotencyKey: (string) Str::uuid(),
        ), $this->admin);
        // Diya (Early Years): unpaid -- her Charge carries the Early Years Campus.
        $assess($this->students['diya'], '18000.00');
        $assess($this->students['meera'], '30000.00');
    }

    private function transportAndLibrary(): void
    {
        $main = $this->campuses['gachibowli_main'];
        $this->b->inSchool(function () use ($main): void {
            $route = TransportRoute::query()->create([
                'school_id' => $this->school->id, 'campus_id' => $main->id, 'code' => 'GCB-R1',
                'name' => 'Kondapur Route', 'description' => 'Kondapur - Gachibowli loop (demo)', 'status' => 'active',
            ]);
            $stops = [];
            foreach (['Kondapur Junction', 'Botanical Garden', 'Gachibowli Stadium'] as $i => $name) {
                $stops[] = TransportStop::query()->create([
                    'school_id' => $this->school->id, 'route_id' => $route->id, 'name' => $name,
                    'sequence' => $i + 1, 'address' => $name.', Hyderabad (demo)', 'status' => 'active',
                ]);
            }
            $riders = app(TransportStudentAssignmentService::class);
            $riders->assign($this->students['aarav'], $route, $stops[0], $stops[0], $this->admin);
            $riders->assign($this->students['kabir'], $route, $stops[1], $stops[1], $this->admin);

            $title = LibraryTitle::query()->create(['school_id' => $this->school->id, 'title' => 'The Adventures of Tenali Raman', 'author' => 'Retold (demo)', 'isbn' => null, 'status' => 'active']);
            $loans = app(LibraryLoanService::class);
            foreach (['aarav' => 'GCB-LIB-0001', 'ananya' => 'GCB-LIB-0002'] as $key => $code) {
                $copy = LibraryCopy::query()->create(['school_id' => $this->school->id, 'library_title_id' => $title->id, 'campus_id' => $main->id, 'code' => $code, 'status' => 'active']);
                $loans->checkout($copy, $this->students[$key], Carbon::now()->addDays(10), $this->admin);
            }
        }, $this->school);
    }

    /**
     * One first-period English register for Grade 5 · A on the last five
     * school days before the reset day (School-local), so the profile's
     * 30-day window always has records. Aarav is absent once and late once.
     */
    private function attendance(): void
    {
        $main = $this->campuses['gachibowli_main'];
        $teacher = $this->b->inSchool(function () use ($main): Employee {
            $category = EmployeeCategory::query()->create(['school_id' => $this->school->id, 'name' => 'Teaching', 'code' => 'TEACH', 'status' => 'active']);
            $department = Department::query()->create(['school_id' => $this->school->id, 'name' => 'Academics', 'code' => 'ACAD', 'status' => 'active']);
            $position = Position::query()->create(['school_id' => $this->school->id, 'name' => 'Primary Teacher', 'code' => 'PRT', 'status' => 'active']);
            $employee = Employee::query()->create([
                'school_id' => $this->school->id, 'user_id' => null,
                'employee_number' => app(EmployeeNumberFormatter::class)->format(app(EmployeeNumberAllocator::class)->allocate($this->school)),
                'full_name' => 'Shalini Varma', 'record_status' => 'active',
            ]);
            $record = EmploymentRecord::query()->create([
                'school_id' => $this->school->id, 'employee_id' => $employee->id, 'employment_type' => 'permanent', 'starts_on' => '2024-06-01',
                'ends_on' => null, 'probation_ends_on' => null, 'status' => 'active', 'employee_category_id' => $category->id,
            ]);
            EmployeeAssignment::query()->create([
                'school_id' => $this->school->id, 'employment_record_id' => $record->id, 'campus_id' => $main->id, 'department_id' => $department->id,
                'position_id' => $position->id, 'is_primary' => true, 'starts_on' => '2024-06-01', 'ends_on' => null,
            ]);

            return $employee;
        }, $this->school);

        $room = $this->b->inSchool(fn () => Room::query()->create([
            'school_id' => $this->school->id, 'campus_id' => $main->id, 'name' => 'Room 5A', 'code' => 'R5A', 'room_type' => 'classroom', 'capacity' => 30, 'status' => 'active',
        ]), $this->school);
        $period = $this->b->inSchool(fn () => app(TimetablePeriodService::class)->create($this->school, [
            'code' => 'P1', 'name' => 'Period 1', 'start_time' => '08:30', 'end_time' => '09:15', 'sort_order' => 1,
        ], $this->admin), $this->school);

        $schedule = app(TimetableScheduleService::class);
        $entries = [];
        for ($day = 1; $day <= 5; $day++) {
            $entries[$day] = $schedule->create($this->school, $this->offerings['G5-ENG'], $this->sections['G5-A'], $teacher, $room, $period, $day, $this->admin);
        }

        $dates = [];
        for ($date = CarbonImmutable::parse(SchoolTimezone::today($this->school))->subDay(); count($dates) < 5; $date = $date->subDay()) {
            if ($date->isoWeekday() <= 5 && $date->toDateString() >= $this->year->starts_on->toDateString()) {
                $dates[] = $date;
            }
        }

        $submission = app(AttendanceSubmissionService::class);
        foreach (array_reverse($dates) as $index => $date) {
            $this->b->inSchool(function () use ($submission, $entries, $date, $index): void {
                $records = [];
                foreach (DB::table('student_enrollments')->where('section_id', $this->sections['G5-A']->id)->where('status', 'active')->orderBy('roll_number')->get(['id', 'student_id']) as $row) {
                    $isAarav = (string) $row->student_id === (string) $this->students['aarav']->id;
                    $records[] = ['student_enrollment_id' => $row->id, 'status' => match (true) {
                        $isAarav && $index === 1 => 'absent',
                        $isAarav && $index === 3 => 'late',
                        default => 'present',
                    }];
                }
                $submission->guarded(fn () => DB::transaction(fn () => $submission->submit($this->school, $entries[$date->isoWeekday()]->id, $date->toDateString(), $records, $this->admin)));
            }, $this->school);
        }
    }

    /** An existing role template on an existing Campus-grant pattern: Principal for the Early Years Campus only. */
    private function campusPrincipal(): void
    {
        $principal = $this->b->user('Farida Siddiqui (Principal, Early Years Campus)', 'gachibowli.ey.principal@example.test');
        $this->b->assignSchoolRole($this->b->member($principal, $this->school), 'principal', $this->campuses['gachibowli_early']->id);
        $this->b->account('Principal — Campus: Gachibowli Early Years', $principal, $this->school->name, 'principal for ONE Campus: the Student directory and overview of Early Years Students only; never the whole-School profile or another Campus');
    }
}
