<?php

/*
 * ORG.10 integrated local UAT fixture (ADR 0076 §78). Runs AFTER `ddev demo-reset`
 * and adds only what the demo lacks for ORG.10: a second Campus in the Demo School
 * with a Section, Campus-scoped and special staff identities, a Staff+Guardian,
 * and a TOTP factor with known secret for every UAT account (local demo only).
 *
 *   ddev demo-reset
 *   ddev exec -d /var/www/html/apps/platform "php artisan tinker --execute=\"require '/var/www/html/docs/uat/org-10/harness/fixture.php';\""
 *
 * Identities and role grants go through the same demo-guarded local seams as
 * DemoDataBuilder (LocalCatalogueFixtures); the Campus goes through
 * CampusAdministrationService and Guardian links through AccountLinkService.
 * Every business operation is exercised by the HTTP harness itself, never here.
 * Writes docs-external state to apps/platform/storage/app/org10-uat/fixture.json
 * (gitignored: it holds local demo TOTP secrets and recovery codes).
 */

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Campuses\Application\CampusAdministrationService;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\HR\Application\EmployeeNumberAllocator;
use App\Domain\HR\Application\EmployeeNumberFormatter;
use App\Domain\HR\Infrastructure\Department;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\HR\Infrastructure\EmployeeCategory;
use App\Domain\HR\Infrastructure\EmploymentRecord;
use App\Domain\HR\Infrastructure\Position;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Identity\Application\Portal\GuardianPortalRoleGrants;
use App\Models\Campus;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Auth\Mfa\MfaEnrollmentService;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Database\Seeders\Demo\DemoEnvironmentGuard;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

DemoEnvironmentGuard::assertLocalDdev((string) app()->environment(), getenv('IS_DDEV_PROJECT') ?: null, config('database.connections'));

$ctx = app(TenantContext::class);
$password = 'Demo1234!';
$schoolA = School::query()->where('slug', 'lycenza-demo')->first() ?? School::query()->where('code', 'LDS')->firstOrFail();
$schoolB = School::query()->where('code', 'LDA')->firstOrFail();
$admin = User::query()->where('email', 'school.admin@example.test')->firstOrFail();
$in = fn (callable $fn, ?School $s = null) => $ctx->withSchool($s ?? $schoolA, $fn);

$campusA1 = $in(fn () => Campus::query()->where('code', 'MAIN')->firstOrFail());
$campusA2 = $in(fn () => Campus::query()->where('code', 'NORTH')->first())
    ?? app(CampusAdministrationService::class)->create($schoolA, ['name' => 'North Campus', 'code' => 'NORTH'], $admin);

$year = $in(fn () => AcademicYear::query()->where('code', 'AY2026')->firstOrFail());
$g6 = $in(fn () => GradeLevel::query()->where('code', 'G6')->first() ?? GradeLevel::query()->orderBy('sequence')->firstOrFail());
$sectionA2 = $in(fn () => Section::query()->where('campus_id', $campusA2->id)->where('academic_year_id', $year->id)->first()
    ?? Section::query()->create([
        'school_id' => $schoolA->id, 'academic_year_id' => $year->id, 'campus_id' => $campusA2->id,
        'grade_level_id' => $g6->id, 'name' => 'N', 'code' => 'N', 'capacity' => 40, 'status' => 'active',
    ]));

$user = function (string $name, string $email) use ($password): User {
    $u = User::query()->where('email', $email)->first();
    if ($u === null) {
        $u = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password]);
        $u->forceFill(['email_verified_at' => now()])->save();
    }

    return $u;
};
$member = fn (User $u, School $s): SchoolMembership => SchoolMembership::query()->firstOrCreate(
    ['user_id' => $u->id, 'school_id' => $s->id],
    ['status' => 'active', 'joined_at' => now()],
);
$grant = function (SchoolMembership $m, string $roleKey, ?Campus $campus = null) use ($in) {
    $role = Role::query()->where('key', $roleKey)->where('scope', 'school')->firstOrFail();
    $exists = $in(fn () => DB::table('membership_role_assignments')->where('school_membership_id', $m->id)
        ->where('role_id', $role->id)->whereNull('revoked_at')
        ->where(fn ($q) => $campus ? $q->where('campus_id', $campus->id) : $q->whereNull('campus_id'))->exists(), $m->school);
    if (! $exists) {
        LocalCatalogueFixtures::grantRole($m, $role, $campus?->id);
    }
};

// HR reference rows (the demo's).
[$category, $department, $position] = $in(fn () => [
    EmployeeCategory::query()->where('code', 'NONTEACH')->firstOrFail(),
    Department::query()->where('code', 'ADMIN')->firstOrFail(),
    Position::query()->where('code', 'OFFICE')->firstOrFail(),
]);
$employ = function (User $u, string $starts, ?string $ends, string $status, ?Campus $campus) use ($in, $schoolA, $category, $department, $position, $campusA1) {
    return $in(function () use ($u, $starts, $ends, $status, $campus, $schoolA, $category, $department, $position, $campusA1) {
        if (Employee::query()->where('user_id', $u->id)->exists()) {
            return Employee::query()->where('user_id', $u->id)->first();
        }
        $e = Employee::query()->create([
            'school_id' => $schoolA->id, 'user_id' => $u->id,
            'employee_number' => app(EmployeeNumberFormatter::class)->format(app(EmployeeNumberAllocator::class)->allocate($schoolA)),
            'full_name' => preg_replace('/ \(.*\)$/', '', $u->name), 'record_status' => 'active',
        ]);
        $r = EmploymentRecord::query()->create([
            'school_id' => $schoolA->id, 'employee_id' => $e->id, 'employment_type' => 'permanent',
            'starts_on' => $starts, 'ends_on' => $ends, 'status' => $status, 'employee_category_id' => $category->id,
        ]);
        EmployeeAssignment::query()->create([
            'school_id' => $schoolA->id, 'employment_record_id' => $r->id, 'campus_id' => ($campus ?? $campusA1)->id,
            'department_id' => $department->id, 'position_id' => $position->id, 'is_primary' => true,
            'starts_on' => $starts, 'ends_on' => $ends,
        ]);

        return $e;
    });
};

// [key, name, email, [[role, campus|null]...], employment: [starts, ends, status, campus] | null]
$people = [
    ['librarianA1', 'Uma Rao (Librarian, Main Campus only)', 'uat.librarian.a1@example.test', [['librarian', $campusA1]], ['2024-06-01', null, 'active', $campusA1]],
    ['cashierA1', 'Imran Shaikh (Cashier, Main Campus)', 'uat.cashier.a1@example.test', [['cashier', $campusA1]], ['2024-06-01', null, 'active', $campusA1]],
    ['cashierA2', 'Neha Joshi (Cashier, North Campus)', 'uat.cashier.a2@example.test', [['cashier', $campusA2]], ['2024-06-01', null, 'active', $campusA2]],
    ['principalA2', 'Farah Khan (Principal, North Campus)', 'uat.principal.a2@example.test', [['principal', $campusA2]], ['2024-06-01', null, 'active', $campusA2]],
    ['selfService', 'Ravi Kumar (Staff Self-Service only)', 'uat.selfservice@example.test', [['staff_self_service', null]], ['2024-06-01', null, 'active', $campusA1]],
    ['hr', 'Anita Desai (HR Officer)', 'uat.hr@example.test', [['hr_officer', null]], ['2024-06-01', null, 'active', $campusA1]],
    ['payroll', 'Vikram Seth (Payroll Officer)', 'uat.payroll@example.test', [['payroll_officer', null]], ['2024-06-01', null, 'active', $campusA1]],
    ['admissions', 'Meera Pillai (Admissions Officer)', 'uat.admissions@example.test', [['admissions_officer', null]], ['2024-06-01', null, 'active', $campusA1]],
    ['formerTeacher', 'Sunil Mehta (Former Teacher)', 'uat.teacher.former@example.test', [['teacher', null], ['staff_self_service', null]], ['2023-06-01', '2026-09-30', 'separated', $campusA1]],
    ['futureTeacher', 'Pooja Nair (Future Teacher)', 'uat.teacher.future@example.test', [['teacher', null], ['staff_self_service', null]], ['2026-12-01', null, 'active', $campusA1]],
    ['staffGuardian', 'Deepa Menon (Teacher + Guardian)', 'uat.staffguardian@example.test', [['teacher', null], ['staff_self_service', null]], ['2024-06-01', null, 'active', $campusA1]],
    ['roleOnly', 'Kiran Bose (Teacher role, no Employee)', 'uat.roleonly@example.test', [['teacher', null]], null],
];

$out = ['schools' => ['A' => $schoolA->id, 'B' => $schoolB->id], 'campuses' => ['A1' => $campusA1->id, 'A2' => $campusA2->id], 'sectionA2' => $sectionA2->id, 'accounts' => []];
$accounts = [];
foreach ($people as [$key, $name, $email, $roles, $employment]) {
    $u = $user($name, $email);
    $m = $member($u, $schoolA);
    foreach ($roles as [$roleKey, $campus]) {
        $grant($m, $roleKey, $campus);
    }
    if ($employment !== null) {
        $employ($u, ...$employment);
    }
    app(CapabilityResolver::class)->forgetCache($u, $schoolA);
    $accounts[$key] = $u;
}

// Staff+Guardian: the teacher above is ALSO the activated Guardian account of a
// Guardian who has a Student in G6-A (same User, same membership).
$sgMembership = $member($accounts['staffGuardian'], $schoolA);
$linkedGuardianIds = $in(fn () => DB::table('student_guardian_account_links')->whereNotNull('guardian_id')->where('status', 'active')->where('school_membership_id', '!=', $sgMembership->id)->pluck('guardian_id')->all());
$sgGuardian = $in(fn () => DB::table('student_guardian_account_links')->where('school_membership_id', $sgMembership->id)->where('status', 'active')->whereNotNull('guardian_id')->value('guardian_id'))
    ? $in(fn () => Guardian::query()->findOrFail(DB::table('student_guardian_account_links')->where('school_membership_id', $sgMembership->id)->where('status', 'active')->value('guardian_id')))
    : $in(fn () => Guardian::query()->whereNotIn('id', $linkedGuardianIds)->orderBy('created_at')->skip(3)->firstOrFail());
$alreadyLinked = $in(fn () => DB::table('student_guardian_account_links')->where('guardian_id', $sgGuardian->id)->where('status', 'active')->exists());
if (! $alreadyLinked) {
    app(AccountLinkService::class)->linkGuardian($schoolA, $sgGuardian, $sgMembership, $admin);
    $in(fn () => DB::transaction(fn () => app(GuardianPortalRoleGrants::class)->grant($schoolA, $sgMembership, $accounts['staffGuardian'])));
}
$out['staffGuardianGuardianId'] = $sgGuardian->id;

// A User in School A AND School B with authority in both: the demo's multi.school
// (principal at A, school_admin at B) -- recorded, not created.
$emails = [
    'school.admin@example.test', 'principal@example.test', 'hr.payroll@example.test', 'multi.school@example.test', 'annexe.admin@example.test',
    'platform.admin@example.test', 'platform.manager@example.test', 'platform.auditor@example.test', 'group.admin@example.test',
    'teacher@example.test', 'student@example.test', 'guardian01@example.test', 'finance.officer@example.test', 'library.operator@example.test',
    'transport.operator@example.test', 'reception@example.test', 'hostel.warden@example.test', 'canteen.operator@example.test', 'communications@example.test',
];
foreach ($people as [$key, , $email]) {
    $emails[] = $email;
}

// A TOTP factor with a known secret for every account (local demo only).
$g2fa = app(Google2FA::class);
foreach ($emails as $email) {
    $u = User::query()->where('email', $email)->firstOrFail();
    $row = ['id' => $u->id, 'email' => $email];
    if (! $u->mfaFactors()->where('status', 'active')->exists()) {
        $begun = app(MfaEnrollmentService::class)->begin($u);
        $row['recovery'] = app(MfaEnrollmentService::class)->confirm($u, $g2fa->getCurrentOtp($begun['secret']));
        $row['secret'] = $begun['secret'];
        $row['enrolledStep'] = (int) floor(time() / 30);
    }
    $out['accounts'][$email] = $row;
}

$dir = storage_path('app/org10-uat');
@mkdir($dir, 0775, true);
$file = $dir.'/fixture.json';
$previous = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
foreach ($out['accounts'] as $email => $row) {
    if (! isset($row['secret']) && isset($previous['accounts'][$email]['secret'])) {
        $out['accounts'][$email] = $previous['accounts'][$email];
    }
}
file_put_contents($file, json_encode($out, JSON_PRETTY_PRINT));
echo 'ORG.10 fixture ready: '.count($out['accounts']).' accounts, Campus A2 '.$campusA2->id.PHP_EOL;
