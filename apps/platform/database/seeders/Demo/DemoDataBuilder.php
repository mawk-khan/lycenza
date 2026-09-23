<?php

namespace Database\Seeders\Demo;

use App\Domain\AcademicStructure\Application\AcademicTermService;
use App\Domain\AcademicStructure\Application\AcademicYearService;
use App\Domain\AcademicStructure\Application\ElectiveGroupService;
use App\Domain\AcademicStructure\Infrastructure\AcademicDepartment;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\Subject;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Guardians\Application\GuardianContactService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeeCategory;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Domain\Identity\Application\AccountInvitationService;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Identity\Application\GuardianAccountActivationService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentSubjectEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\Campus;
use App\Models\MembershipRoleAssignment;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Builds the "Lycenza Demo School" local review dataset through the
 * application's real models, Application services and tenant context
 * (TenantContext::withSchool + the unprivileged, RLS-bound runtime
 * connection) -- the same rules tests/Concerns/CreatesTenancyFixtures
 * follows. There is no RLS or authorization bypass anywhere in here.
 *
 * Every person, school, e-mail address and phone number is fictional;
 * every e-mail address uses the reserved `.test` TLD.
 */
final class DemoDataBuilder
{
    /** LOCAL DEMO ONLY -- documented in docs/development/DDEV-DEMO-REVIEW.md. */
    public const DEMO_PASSWORD = 'Demo1234!';

    public const SCHOOL_SLUG = 'lycenza-demo';

    public const SECOND_SCHOOL_SLUG = 'lycenza-demo-annexe';

    /**
     * Custom, non-system role created ONLY for the demo: no seeded
     * system role holds the HR-sensitive / payslip / statutory-payroll
     * capabilities, and the application has no role-management UI, so
     * without it those implemented screens could not be reviewed at
     * all. It uses the real roles / role_capabilities /
     * membership_role_assignments tables -- the same mechanism the test
     * suite's createUserWithCapabilities() uses.
     */
    public const DEMO_HR_PAYROLL_ROLE_KEY = 'demo.hr_payroll_officer';

    /** @var array<int, string> */
    public const DEMO_HR_PAYROLL_CAPABILITIES = [
        'hr.employees.view', 'hr.employees.manage',
        'hr.employees.personal.view', 'hr.employees.personal.manage',
        'hr.employees.assignments.view', 'hr.employees.assignments.manage',
        'hr.employees.qualifications.view', 'hr.employees.qualifications.manage',
        'hr.employees.documents.view', 'hr.employees.documents.manage',
        'hr.employees.sensitive.view', 'hr.employees.sensitive.manage',
        'hr.employees.notes.view', 'hr.employees.notes.manage',
        'hr.departments.view', 'hr.departments.manage',
        'hr.positions.view', 'hr.positions.manage',
        'hr.categories.view', 'hr.categories.manage',
        'payroll.structures.view', 'payroll.structures.manage',
        'payroll.compensation.view',
        'payroll.compensation.sensitive.view', 'payroll.compensation.sensitive.manage',
        'payroll.periods.manage',
        'payroll.runs.view', 'payroll.runs.prepare', 'payroll.runs.approve',
        'payroll.runs.post', 'payroll.runs.reverse',
        'payroll.accounting.manage',
        'payroll.statutory.view', 'payroll.statutory.manage',
        'payroll.statutory.identifiers.view', 'payroll.statutory.identifiers.manage',
        'payroll.statutory.exports.generate',
    ];

    private const FIRST_NAMES = [
        'Aarav', 'Diya', 'Vihaan', 'Ananya', 'Arjun', 'Isha', 'Kabir', 'Meera', 'Reyansh', 'Saanvi',
        'Vivaan', 'Aanya', 'Aditya', 'Myra', 'Krishna', 'Kiara', 'Ishaan', 'Anika', 'Rohan', 'Tara',
        'Dhruv', 'Nisha', 'Kunal', 'Riya', 'Aryan', 'Pooja', 'Neel', 'Sneha', 'Yash', 'Zara',
        'Om', 'Lavanya', 'Parth', 'Ira', 'Samar', 'Navya',
    ];

    private const LAST_NAMES = [
        'Sharma', 'Iyer', 'Reddy', 'Khan', 'Patel', 'Nair', 'Das', 'Menon', 'Gupta', 'Rao',
        'Singh', 'Joshi', 'Kulkarni', 'Banerjee', 'Pillai', 'Mehta', 'Verma', 'Chatterjee',
    ];

    private const GUARDIAN_FIRST_NAMES = [
        'Priya', 'Rajesh', 'Sunita', 'Imran', 'Kavita', 'Suresh', 'Farah', 'Anil', 'Lakshmi', 'Vikram',
        'Deepa', 'Manoj', 'Rekha', 'Sanjay', 'Asha', 'Rahul', 'Geeta', 'Nikhil',
    ];

    public School $school;

    public School $secondSchool;

    public Campus $campus;

    public User $admin;

    public AcademicYear $currentYear;

    public AcademicYear $nextYear;

    /** @var array<string, GradeLevel> */
    public array $gradeLevels = [];

    /** @var array<string, Section> keyed "G6-A" */
    public array $sections = [];

    /** @var array<string, Subject> */
    public array $subjects = [];

    /** @var array<string, SubjectOffering> keyed "G6-MATH" */
    public array $offerings = [];

    /** @var array<string, Room> */
    public array $rooms = [];

    /** @var array<string, array<int, Student>> keyed by section key */
    public array $studentsBySection = [];

    /** @var array<int, Student> */
    public array $students = [];

    /** @var array<int, Guardian> */
    public array $guardians = [];

    /** @var array<string, Employee> */
    public array $employees = [];

    /** @var array<int, array{persona: string, email: string, school: string, access: string}> */
    private array $accounts = [];

    /** @var array<int, string> */
    private array $notes = [];

    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function build(): DemoBuildResult
    {
        DemoEnvironmentGuard::assertLocalDdevOrTesting(
            (string) app()->environment(),
            getenv('IS_DDEV_PROJECT') === false ? null : (string) getenv('IS_DDEV_PROJECT'),
            (array) config('database.connections'),
            (string) config('database.testing_database'),
        );

        fake()->seed(20260922);

        $this->buildSchoolsAndStaffAccounts();
        $this->buildAcademicStructure();
        $this->buildStudentsAndGuardians();
        $this->buildHr();
        $this->buildIdentityLinkedAccounts();
        $this->buildOperationsDeskAccounts();

        (new DemoModuleData($this))->build();

        return new DemoBuildResult($this->school, $this->secondSchool, $this->accounts, $this->notes);
    }

    public function note(string $note): void
    {
        $this->notes[] = $note;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function inSchool(callable $callback, ?School $school = null): mixed
    {
        return $this->context->withSchool($school ?? $this->school, $callback);
    }

    // ------------------------------------------------------------------
    // Schools, platform/school staff accounts
    // ------------------------------------------------------------------

    private function buildSchoolsAndStaffAccounts(): void
    {
        $this->school = School::query()->create([
            'name' => 'Lycenza Demo School',
            'slug' => self::SCHOOL_SLUG,
            'status' => 'active',
            'timezone' => 'Asia/Kolkata',
            'default_locale' => 'en',
            'legal_name' => 'Lycenza Demo School (fictional)',
            'code' => 'LDS',
            'email' => 'office@lycenza-demo.example.test',
            'phone' => '+91 90000 00000',
            'address_line1' => '1 Demo Road',
            'city' => 'Bengaluru',
            'state_region' => 'Karnataka',
            'postal_code' => '560001',
            'country_code' => 'IN',
        ]);

        $this->secondSchool = School::query()->create([
            'name' => 'Lycenza Demo Annexe School',
            'slug' => self::SECOND_SCHOOL_SLUG,
            'status' => 'active',
            'timezone' => 'Asia/Kolkata',
            'default_locale' => 'en',
            'code' => 'LDA',
            'country_code' => 'IN',
        ]);

        $this->campus = $this->inSchool(fn () => Campus::query()->create([
            'school_id' => $this->school->id,
            'name' => 'Main Campus',
            'code' => 'MAIN',
            'status' => 'active',
        ]));

        $platformAdmin = $this->user('Platform Admin (Demo)', 'platform.admin@example.test');
        PlatformRoleAssignment::query()->create([
            'user_id' => $platformAdmin->id,
            'role_id' => $this->role('platform_super_admin', 'platform')->id,
        ]);
        $this->account('Platform Super Admin', $platformAdmin, '(none -- platform scope)', 'Platform capabilities only; there is no platform web UI yet');

        $this->admin = $this->user('Asha Rao (School Admin)', 'school.admin@example.test');
        $this->assignSchoolRole($this->member($this->admin, $this->school), 'school_admin');
        $this->account('School Admin', $this->admin, $this->school->name, 'All school_admin capabilities (seeded system role)');

        $principal = $this->user('Dr. Vikram Menon (Principal)', 'principal@example.test');
        $this->assignSchoolRole($this->member($principal, $this->school), 'principal');
        $this->account('Principal', $principal, $this->school->name, 'principal system role: academics, students, operations; no Finance/Payroll');

        $hrPayroll = $this->user('Farah Khan (HR & Payroll, demo role)', 'hr.payroll@example.test');
        $role = Role::query()->create([
            'key' => self::DEMO_HR_PAYROLL_ROLE_KEY,
            'name' => 'Demo: HR & Payroll Officer',
            'scope' => 'school',
            'is_system' => false,
        ]);
        $role->capabilities()->sync(self::DEMO_HR_PAYROLL_CAPABILITIES);
        $this->assignSchoolRole($this->member($hrPayroll, $this->school), self::DEMO_HR_PAYROLL_ROLE_KEY);
        $this->account('HR & Payroll Officer (demo-only custom role)', $hrPayroll, $this->school->name, 'HR incl. sensitive records, payslips, statutory payroll');

        $multiSchool = $this->user('Rahul Joshi (two-school admin)', 'multi.school@example.test');
        $this->assignSchoolRole($this->member($multiSchool, $this->school), 'principal');
        $this->assignSchoolRole($this->member($multiSchool, $this->secondSchool), 'school_admin');
        $this->account('Multi-school member', $multiSchool, 'Both schools', 'Principal at Demo School, School Admin at Annexe -- shows School switching');

        $annexeAdmin = $this->user('Meena Das (Annexe Admin)', 'annexe.admin@example.test');
        $this->assignSchoolRole($this->member($annexeAdmin, $this->secondSchool), 'school_admin');
        $this->account('School Admin (second school)', $annexeAdmin, $this->secondSchool->name, 'school_admin at the Annexe only -- tenant-isolation check');

        $this->buildSecondSchool();
    }

    private function buildSecondSchool(): void
    {
        $this->inSchool(function () {
            Campus::query()->create([
                'school_id' => $this->secondSchool->id,
                'name' => 'Annexe Campus',
                'code' => 'ANX',
                'status' => 'active',
            ]);

            foreach (['Kiran Annexe', 'Lata Annexe', 'Mohan Annexe'] as $i => $name) {
                [$first, $last] = explode(' ', $name);
                Student::query()->create([
                    'school_id' => $this->secondSchool->id,
                    'student_number' => 'ANX-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                    'first_name' => $first,
                    'last_name' => $last,
                    'date_of_birth' => '2014-0'.($i + 1).'-15',
                    'status' => 'active',
                ]);
            }
        }, $this->secondSchool);
    }

    // ------------------------------------------------------------------
    // Academic structure (Phase 0D / 1F)
    // ------------------------------------------------------------------

    private function buildAcademicStructure(): void
    {
        $years = app(AcademicYearService::class);
        $terms = app(AcademicTermService::class);

        $previous = $years->create($this->school, ['name' => '2025-26', 'code' => 'AY2025', 'starts_on' => '2025-04-01', 'ends_on' => '2026-03-31'], $this->admin);
        $years->activate($previous, $this->admin);

        $this->currentYear = $years->create($this->school, ['name' => '2026-27', 'code' => 'AY2026', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31'], $this->admin);
        // Activating 2026-27 closes 2025-26 in the same transaction (rule 65).
        $this->currentYear = $years->activate($this->currentYear, $this->admin);

        $this->nextYear = $years->create($this->school, ['name' => '2027-28', 'code' => 'AY2027', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31'], $this->admin);

        $terms->create($this->currentYear, ['name' => 'Term 1', 'code' => 'T1', 'starts_on' => '2026-04-01', 'ends_on' => '2026-09-30', 'sequence' => 1], $this->admin);
        $terms->create($this->currentYear, ['name' => 'Term 2', 'code' => 'T2', 'starts_on' => '2026-10-01', 'ends_on' => '2027-03-31', 'sequence' => 2], $this->admin);

        $this->inSchool(function () {
            foreach ([6, 7, 8] as $n) {
                $this->gradeLevels["G{$n}"] = GradeLevel::query()->create([
                    'school_id' => $this->school->id,
                    'name' => "Grade {$n}",
                    'code' => "G{$n}",
                    'sequence' => $n,
                    'education_stage' => null,
                    'status' => 'active',
                ]);
            }

            $departments = [];
            foreach (['SCI' => 'Science', 'LANG' => 'Languages', 'MATHS' => 'Mathematics', 'HUM' => 'Humanities'] as $code => $name) {
                $departments[$code] = AcademicDepartment::query()->create([
                    'school_id' => $this->school->id,
                    'name' => $name,
                    'code' => $code,
                    'status' => 'active',
                ]);
            }

            $subjectDefinitions = [
                'ENG' => ['English', 'LANG', 'core'],
                'HIN' => ['Hindi', 'LANG', 'core'],
                'MATH' => ['Mathematics', 'MATHS', 'core'],
                'SCI' => ['Science', 'SCI', 'core'],
                'SST' => ['Social Studies', 'HUM', 'core'],
                'COMP' => ['Computer Science', 'SCI', 'core'],
                'FRE' => ['French', 'LANG', 'elective'],
                'SAN' => ['Sanskrit', 'LANG', 'elective'],
            ];
            foreach ($subjectDefinitions as $code => [$name, $department, $type]) {
                $this->subjects[$code] = Subject::query()->create([
                    'school_id' => $this->school->id,
                    'academic_department_id' => $departments[$department]->id,
                    'name' => $name,
                    'code' => $code,
                    'short_name' => $code,
                    'subject_type' => $type,
                    'status' => 'active',
                ]);
            }

            foreach (['R101' => 'Room 101', 'R102' => 'Room 102', 'R201' => 'Room 201', 'R202' => 'Room 202', 'R301' => 'Room 301', 'R302' => 'Room 302', 'LAB1' => 'Science Lab'] as $code => $name) {
                $this->rooms[$code] = Room::query()->create([
                    'school_id' => $this->school->id,
                    'campus_id' => $this->campus->id,
                    'name' => $name,
                    'code' => $code,
                    'room_type' => $code === 'LAB1' ? 'lab' : 'classroom',
                    'capacity' => 40,
                    'status' => 'active',
                ]);
            }

            foreach ($this->gradeLevels as $gradeKey => $grade) {
                foreach (['A', 'B'] as $letter) {
                    $this->sections["{$gradeKey}-{$letter}"] = Section::query()->create([
                        'school_id' => $this->school->id,
                        'academic_year_id' => $this->currentYear->id,
                        'campus_id' => $this->campus->id,
                        'grade_level_id' => $grade->id,
                        'name' => $letter,
                        'code' => $letter,
                        'capacity' => 40,
                        'status' => 'active',
                    ]);
                }

                foreach (['ENG', 'HIN', 'MATH', 'SCI', 'SST', 'COMP'] as $sequence => $subjectCode) {
                    $this->offerings["{$gradeKey}-{$subjectCode}"] = $this->offering($grade, $subjectCode, true, $sequence + 1);
                }
            }

            // Grade 8 third-language elective: French XOR Sanskrit (Phase 1F).
            $this->offerings['G8-FRE'] = $this->offering($this->gradeLevels['G8'], 'FRE', false, 7);
            $this->offerings['G8-SAN'] = $this->offering($this->gradeLevels['G8'], 'SAN', false, 8);

            // Next year's Grade 6-8 sections/offerings so the rollover
            // planning screens have a real target year to map into.
            foreach ($this->gradeLevels as $gradeKey => $grade) {
                Section::query()->create([
                    'school_id' => $this->school->id,
                    'academic_year_id' => $this->nextYear->id,
                    'campus_id' => $this->campus->id,
                    'grade_level_id' => $grade->id,
                    'name' => 'A',
                    'code' => 'A',
                    'capacity' => 40,
                    'status' => 'active',
                ]);
            }
        });

        $electives = app(ElectiveGroupService::class);
        $group = $electives->create($this->school, $this->currentYear, $this->campus, $this->gradeLevels['G8'], 'Third Language', 'L3', $this->admin);
        $electives->assignOffering($group, $this->offerings['G8-FRE'], $this->admin);
        $electives->assignOffering($group, $this->offerings['G8-SAN'], $this->admin);
    }

    private function offering(GradeLevel $grade, string $subjectCode, bool $required, int $sequence): SubjectOffering
    {
        return SubjectOffering::query()->create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->currentYear->id,
            'campus_id' => $this->campus->id,
            'grade_level_id' => $grade->id,
            'subject_id' => $this->subjects[$subjectCode]->id,
            'is_required' => $required,
            'sequence' => $sequence,
            'weekly_periods_target' => $required ? 5 : 3,
            'status' => 'active',
        ]);
    }

    // ------------------------------------------------------------------
    // Students, guardians, enrollments (Phase 1A-1C)
    // ------------------------------------------------------------------

    private function buildStudentsAndGuardians(): void
    {
        $contacts = app(GuardianContactService::class);
        $index = 0;

        foreach ($this->sections as $sectionKey => $section) {
            for ($i = 0; $i < 6; $i++, $index++) {
                $grade = (int) substr($sectionKey, 1, 1);
                $lastName = self::LAST_NAMES[$index % count(self::LAST_NAMES)];

                $student = $this->inSchool(fn () => Student::query()->create([
                    'school_id' => $this->school->id,
                    'student_number' => 'LDS-'.str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                    'first_name' => self::FIRST_NAMES[$index % count(self::FIRST_NAMES)],
                    'last_name' => $lastName,
                    // Grade 6 ~ age 11 in 2026.
                    'date_of_birth' => sprintf('%d-%02d-%02d', 2026 - ($grade + 5), ($index % 12) + 1, ($index % 27) + 1),
                    'status' => 'active',
                ]));
                $this->students[] = $student;
                $this->studentsBySection[$sectionKey][] = $student;

                app(StudentEnrollmentService::class)->enroll($student, $section, (string) ($i + 1), '2026-04-01', $this->admin);

                // Every third student is a younger sibling of the previous
                // student and shares that student's guardian.
                if ($index % 3 === 2 && $this->guardians !== []) {
                    $guardian = end($this->guardians);
                } else {
                    $guardianFirst = self::GUARDIAN_FIRST_NAMES[count($this->guardians) % count(self::GUARDIAN_FIRST_NAMES)];
                    $guardian = $this->inSchool(fn () => Guardian::query()->create([
                        'school_id' => $this->school->id,
                        'first_name' => $guardianFirst,
                        'last_name' => $lastName,
                        'status' => 'active',
                    ]));
                    $this->guardians[] = $guardian;
                    $n = count($this->guardians);
                    $contacts->create($guardian, ContactType::Email, sprintf('guardian%02d@example.test', $n), ['is_primary' => true], $this->admin);
                    $contacts->create($guardian, ContactType::Mobile, sprintf('+9198000%05d', $n), [], $this->admin);
                }

                $this->inSchool(fn () => StudentGuardianRelationship::query()->create([
                    'school_id' => $this->school->id,
                    'student_id' => $student->id,
                    'guardian_id' => $guardian->id,
                    'relationship_type' => $index % 2 === 0 ? RelationshipType::Mother : RelationshipType::Father,
                    'is_primary' => true,
                    'is_legal_guardian' => true,
                    'is_emergency_contact' => true,
                    'is_authorized_pickup' => true,
                ]));

                if ($grade === 8) {
                    $electiveCode = $i % 2 === 0 ? 'FRE' : 'SAN';
                    $offering = $this->offerings["G8-{$electiveCode}"];
                    app(StudentSubjectEnrollmentService::class)->enroll($student, $offering, '2026-04-01', $this->admin);
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // HR (Phase 8A)
    // ------------------------------------------------------------------

    private function buildHr(): void
    {
        $this->inSchool(function () {
            $teaching = EmployeeCategory::query()->create(['school_id' => $this->school->id, 'name' => 'Teaching', 'code' => 'TEACH', 'status' => 'active']);
            $nonTeaching = EmployeeCategory::query()->create(['school_id' => $this->school->id, 'name' => 'Non-Teaching', 'code' => 'NONTEACH', 'status' => 'active']);

            $academics = Department::query()->create(['school_id' => $this->school->id, 'name' => 'Academics', 'code' => 'ACAD', 'status' => 'active']);
            $admin = Department::query()->create(['school_id' => $this->school->id, 'name' => 'Administration', 'code' => 'ADMIN', 'status' => 'active']);
            $transport = Department::query()->create(['school_id' => $this->school->id, 'name' => 'Transport', 'code' => 'TRANS', 'status' => 'active']);

            $positions = [];
            foreach (['TGT' => 'Trained Graduate Teacher', 'PGT' => 'Post Graduate Teacher', 'ACCT' => 'Accountant', 'LIB' => 'Librarian', 'DRV' => 'Driver', 'OFFICE' => 'Office Assistant'] as $code => $name) {
                $positions[$code] = Position::query()->create(['school_id' => $this->school->id, 'name' => $name, 'code' => $code, 'status' => 'active']);
            }

            $staff = [
                'ENG' => ['Nandini Iyer', 'PGT', $academics, $teaching],
                'HIN' => ['Suresh Verma', 'TGT', $academics, $teaching],
                'MATH' => ['Kavya Reddy', 'PGT', $academics, $teaching],
                'SCI' => ['Arvind Pillai', 'PGT', $academics, $teaching],
                'SST' => ['Shabana Qureshi', 'TGT', $academics, $teaching],
                'COMP' => ['Rohit Bansal', 'TGT', $academics, $teaching],
                'FRE' => ['Claire Dsouza', 'TGT', $academics, $teaching],
                'SAN' => ['Ramesh Shastri', 'TGT', $academics, $teaching],
                'ACCT' => ['Prakash Gupta', 'ACCT', $admin, $nonTeaching],
                'LIB' => ['Leela Nair', 'LIB', $admin, $nonTeaching],
                'DRV' => ['Babu Lal', 'DRV', $transport, $nonTeaching],
                'OFFICE' => ['Joseph Thomas', 'OFFICE', $admin, $nonTeaching],
            ];

            $number = 1;
            foreach ($staff as $key => [$name, $positionCode, $department, $category]) {
                $employee = Employee::query()->create([
                    'school_id' => $this->school->id,
                    'user_id' => null,
                    'employee_number' => 'EMP-'.str_pad((string) $number++, 6, '0', STR_PAD_LEFT),
                    'full_name' => $name,
                    'record_status' => 'active',
                ]);

                $record = EmploymentRecord::query()->create([
                    'school_id' => $this->school->id,
                    'employee_id' => $employee->id,
                    'employment_type' => 'permanent',
                    'starts_on' => '2023-06-01',
                    'ends_on' => null,
                    'probation_ends_on' => null,
                    'status' => 'active',
                    'employee_category_id' => $category->id,
                ]);

                EmployeeAssignment::query()->create([
                    'school_id' => $this->school->id,
                    'employment_record_id' => $record->id,
                    'campus_id' => $this->campus->id,
                    'department_id' => $department->id,
                    'position_id' => $positions[$positionCode]->id,
                    'is_primary' => true,
                    'starts_on' => '2023-06-01',
                    'ends_on' => null,
                ]);

                $this->employees[$key] = $employee;
            }
        });
    }

    // ------------------------------------------------------------------
    // Accounts linked to Student / Guardian / Employee records
    // ------------------------------------------------------------------

    private function buildIdentityLinkedAccounts(): void
    {
        // Teacher: an ordinary School member with NO role, linked to an
        // HR Employee record. The application has no teacher role or
        // teacher portal -- this account shows exactly that.
        $teacher = $this->user('Kavya Reddy (Teacher, no role)', 'teacher@example.test');
        $this->member($teacher, $this->school);
        $this->inSchool(fn () => $this->employees['MATH']->forceFill(['user_id' => $teacher->id])->save());
        $this->account('Teacher / staff member (no role)', $teacher, $this->school->name, 'Member with no capabilities; linked to Employee EMP-000003');

        // Student: School member linked to a Student record (Phase 5B).
        $studentRecord = $this->studentsBySection['G8-A'][0];
        $studentUser = $this->user($studentRecord->first_name.' '.$studentRecord->last_name.' (Student)', 'student@example.test');
        app(AccountLinkService::class)->linkStudent($this->school, $studentRecord, $this->member($studentUser, $this->school), $this->admin);
        $this->account('Student account', $studentUser, $this->school->name, 'Member with no capabilities; linked to Student '.$studentRecord->student_number);

        // Guardian: created through the REAL Phase 5D.3 invitation +
        // activation services. The invitation e-mail is delivered to
        // Mailpit; activation then creates the User, membership and link.
        $invitations = app(AccountInvitationService::class);
        $activation = app(GuardianAccountActivationService::class);

        $activatedGuardian = $this->guardians[0];
        $invitation = $invitations->invite($this->school, $activatedGuardian, $this->admin);
        $activation->accept($this->school, $invitation, null, self::DEMO_PASSWORD);
        $guardianUser = User::query()->where('email', 'guardian01@example.test')->firstOrFail();
        $this->account('Guardian account (activated)', $guardianUser, $this->school->name, 'Member with no capabilities; linked to Guardian '.$activatedGuardian->first_name.' '.$activatedGuardian->last_name);

        // A second guardian with a PENDING invitation: the invitation
        // e-mail (with its one-time link) is waiting in Mailpit, so the
        // real browser acceptance flow can be reviewed end to end.
        $pendingGuardian = $this->guardians[1];
        $invitations->invite($this->school, $pendingGuardian, $this->admin);
        $this->note('Pending guardian invitation for guardian02@example.test ('.$pendingGuardian->first_name.' '.$pendingGuardian->last_name.') -- open Mailpit (`ddev mailpit`) and follow the link to review the activation flow.');
    }

    // ------------------------------------------------------------------
    // Operations-desk accounts (demo-only roles, existing capabilities)
    // ------------------------------------------------------------------

    /**
     * One demo-only, non-system school role per existing operations
     * capability family (DemoAccountCatalog::OPERATIONS_DESK_ROLES), so
     * each module can be reviewed by an account that has ONLY that
     * module's access. Same mechanism as the HR & Payroll demo role.
     */
    private function buildOperationsDeskAccounts(): void
    {
        foreach (DemoAccountCatalog::OPERATIONS_DESK_ROLES as $key => $definition) {
            $role = Role::query()->create([
                'key' => $key,
                'name' => $definition['name'],
                'scope' => 'school',
                'is_system' => false,
            ]);
            $role->capabilities()->sync($definition['capabilities']);

            $user = $this->user($definition['user'], $definition['email']);
            $this->assignSchoolRole($this->member($user, $this->school), $key);
            $families = array_values(array_unique(array_map(fn (string $capability) => strtok($capability, '.').'.*', $definition['capabilities'])));
            $this->account($definition['persona'].' (demo-only role)', $user, $this->school->name, 'Only '.implode(' + ', $families).' (existing capabilities)');
        }
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function user(string $name, string $email): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => self::DEMO_PASSWORD,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function member(User $user, School $school): SchoolMembership
    {
        return SchoolMembership::query()->create([
            'user_id' => $user->id,
            'school_id' => $school->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    private function role(string $key, string $scope): Role
    {
        return Role::query()->where('key', $key)->where('scope', $scope)->firstOrFail();
    }

    private function assignSchoolRole(SchoolMembership $membership, string $roleKey): void
    {
        $role = $this->role($roleKey, 'school');

        $this->context->withSchool($membership->school, fn () => MembershipRoleAssignment::query()->create([
            'school_id' => $membership->school_id,
            'school_membership_id' => $membership->id,
            'role_id' => $role->id,
        ]));
    }

    private function account(string $persona, User $user, string $school, string $access): void
    {
        $this->accounts[] = [
            'persona' => $persona,
            'email' => $user->email,
            'school' => $school,
            'access' => $access,
        ];
    }
}
