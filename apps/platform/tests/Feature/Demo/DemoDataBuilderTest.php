<?php

namespace Tests\Feature\Demo;

use App\Domain\Analytics\Application\AnalyticsReadGate;
use App\Domain\Analytics\Application\ReadModels\CurriculumCoverageReadModel;
use App\Domain\Identity\Infrastructure\StudentGuardianAccountLink;
use App\Domain\Payments\Infrastructure\Payment;
use App\Domain\Payments\Infrastructure\PaymentProviderEvent;
use App\Domain\Students\Infrastructure\Student;
use App\Models\GroupRoleAssignment;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Demo\DemoAccountCatalog;
use Database\Seeders\Demo\DemoBuildResult;
use Database\Seeders\Demo\DemoDataBuilder;
use Database\Seeders\Demo\DemoSeeder;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The local DDEV demo dataset (database/seeders/Demo) -- built here
 * against school_os_test inside the suite's rolled-back transaction.
 * Proves the demo accounts, their tenant placement and capability
 * separation are exactly what docs/development/DDEV-DEMO-REVIEW.md
 * documents, through the application's real authorization and HTTP
 * surface (nothing is weakened for the demo).
 */
class DemoDataBuilderTest extends TestCase
{
    private function build(): DemoBuildResult
    {
        return app(DemoDataBuilder::class)->build();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    #[Test]
    public function the_demo_seeder_entry_point_refuses_to_run_outside_local_ddev(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("APP_ENV is 'testing'");

        $this->seed(DemoSeeder::class);
    }

    #[Test]
    public function it_builds_the_documented_accounts_tenants_and_capability_separation(): void
    {
        $result = $this->build();
        $school = $result->school;
        $annexe = $result->secondSchool;
        $resolver = app(CapabilityResolver::class);

        $this->assertSame('Lycenza Demo School', $school->name);
        $this->assertSame('Lycenza Demo Annexe School', $annexe->name);

        $emails = array_column($result->accounts, 'email');
        $this->assertSame([
            'platform.admin@example.test', 'platform.auditor@example.test', 'school.admin@example.test', 'principal@example.test',
            'hr.payroll@example.test', 'multi.school@example.test', 'annexe.admin@example.test',
            'group.admin@example.test', 'teacher@example.test', 'student@example.test', 'guardian01@example.test',
            ...array_column(DemoAccountCatalog::OPERATIONS_DESK_ROLES, 'email'),
        ], $emails);

        // Every login-page shortcut is a real seeded account (and vice versa).
        $this->assertEqualsCanonicalizing($emails, array_column(DemoAccountCatalog::loginShortcuts(), 'email'));

        foreach ($emails as $email) {
            $this->assertStringEndsWith('.test', $email);
            $this->assertTrue(Hash::check(DemoDataBuilder::DEMO_PASSWORD, $this->user($email)->password), $email);
        }

        // --- Tenant placement --------------------------------------------
        $memberships = fn (string $email) => SchoolMembership::query()
            ->where('user_id', $this->user($email)->id)
            ->where('status', 'active')
            ->pluck('school_id')->sort()->values()->all();

        $this->assertSame([], $memberships('platform.admin@example.test'));
        $this->assertSame([], $memberships('group.admin@example.test'));
        $this->assertSame([], $memberships('platform.auditor@example.test'));
        $this->assertSame(['platform.audit.view'], $resolver->platformCapabilities($this->user('platform.auditor@example.test')));
        $this->assertSame([$school->id], $memberships('school.admin@example.test'));
        $this->assertSame([$school->id], $memberships('principal@example.test'));
        $this->assertSame([$school->id], $memberships('hr.payroll@example.test'));
        $this->assertSame([$annexe->id], $memberships('annexe.admin@example.test'));
        $this->assertEqualsCanonicalizing([$school->id, $annexe->id], $memberships('multi.school@example.test'));
        $this->assertSame([$school->id], $memberships('teacher@example.test'));
        $this->assertSame([$school->id], $memberships('student@example.test'));
        $this->assertSame([$school->id], $memberships('guardian01@example.test'));

        // --- Platform role is platform-scoped only -------------------------
        $platformAdmin = $this->user('platform.admin@example.test');
        $this->assertTrue(PlatformRoleAssignment::query()->where('user_id', $platformAdmin->id)->exists());
        $this->assertTrue($resolver->canPlatform($platformAdmin, 'platform.schools.view'));
        $this->assertFalse($resolver->canInSchool($platformAdmin, 'students.view', $school));

        // --- Phase 0N.5: one Group with both Schools; a Group-only admin ---
        $group = SchoolGroup::query()->where('slug', DemoDataBuilder::SCHOOL_GROUP_SLUG)->firstOrFail();
        $this->assertEqualsCanonicalizing([$school->id, $annexe->id], $group->schools()->pluck('schools.id')->all());
        $groupAdmin = $this->user('group.admin@example.test');
        $this->assertSame(['group.reporting.view', 'group.schools.elevate', 'group.schools.view'], $resolver->groupCapabilities($groupAdmin, $group));
        $this->assertSame([], $resolver->platformCapabilities($groupAdmin));
        $this->assertSame([], $resolver->schoolCapabilities($groupAdmin, $school));
        $this->assertSame([], $resolver->groupCapabilities($platformAdmin, $group), 'Platform authority is not Group authority.');
        $this->assertSame($platformAdmin->id, GroupRoleAssignment::query()->where('user_id', $groupAdmin->id)->value('granted_by_user_id'));

        // Phase 0O.11A: offline demo fees are manual Payments recorded by the
        // School Admin -- never fake provider events; a few provider-derived
        // examples remain, each backed by exactly one provider event.
        $payments = app(TenantContext::class)->withSchool($school, fn () => Payment::query()->get());
        $manual = $payments->where('source', 'manual');
        $this->assertNotEmpty($manual);
        $this->assertTrue($manual->every(fn (Payment $p) => $p->provider === null && $p->provider_event_id === null && $p->recorded_by_user_id === $this->user('school.admin@example.test')->id));
        $this->assertSame(
            $payments->where('source', 'provider')->count(),
            app(TenantContext::class)->withSchool($school, fn () => PaymentProviderEvent::query()->count()),
        );

        // --- Capability separation (real seeded roles) --------------------
        $admin = $this->user('school.admin@example.test');
        $principal = $this->user('principal@example.test');
        $hrPayroll = $this->user('hr.payroll@example.test');

        $this->assertTrue($resolver->canInSchool($admin, 'finance.ledger.view', $school));
        $this->assertTrue($resolver->canInSchool($admin, 'finance.payments.record', $school));
        $this->assertTrue($resolver->canInSchool($admin, 'students.manage', $school));
        $this->assertFalse($resolver->canInSchool($admin, 'payroll.compensation.sensitive.view', $school));
        $this->assertFalse($resolver->canInSchool($admin, 'students.view', $annexe));

        $this->assertTrue($resolver->canInSchool($principal, 'students.view', $school));
        $this->assertFalse($resolver->canInSchool($principal, 'finance.ledger.view', $school));
        $this->assertFalse($resolver->canInSchool($principal, 'finance.payments.record', $school));
        $this->assertFalse($resolver->canInSchool($principal, 'payroll.runs.view', $school));

        $this->assertTrue($resolver->canInSchool($hrPayroll, 'payroll.compensation.sensitive.view', $school));
        $this->assertTrue($resolver->canInSchool($hrPayroll, 'payroll.statutory.view', $school));
        $this->assertFalse($resolver->canInSchool($hrPayroll, 'students.view', $school));
        $this->assertFalse($resolver->canInSchool($hrPayroll, 'finance.ledger.view', $school));

        // The demo-only role is a real, non-system school role whose every
        // capability exists in the seeded catalog.
        $demoRole = Role::query()->where('key', DemoDataBuilder::DEMO_HR_PAYROLL_ROLE_KEY)->firstOrFail();
        $this->assertFalse($demoRole->is_system);
        $this->assertSame('school', $demoRole->scope);
        $this->assertEqualsCanonicalizing(DemoDataBuilder::DEMO_HR_PAYROLL_CAPABILITIES, $demoRole->capabilities()->pluck('key')->all());

        $this->assertSame([], $resolver->schoolCapabilities($this->user('student@example.test'), $school), 'No Student portal: no capability.');
        // POR.1 (ADR 0070 §24): an activated Guardian holds exactly the Guardian portal capability,
        // delivered by the closed `guardian`-scope role -- never a staff capability.
        $this->assertEqualsCanonicalizing(['portal.attendance.view', 'portal.communications.view', 'portal.fees.view'], $resolver->schoolCapabilities($this->user('guardian01@example.test'), $school));

        // TCH.3: the demo teacher holds the production Teacher role -- exactly
        // one owned-scope capability, reaching only what her
        // TeachingAssignment (G8-A Mathematics) covers. HRX.4: plus the
        // SEPARATE staff_self_service role (own leave, attendance, payslips).
        $this->assertEqualsCanonicalizing([
            'curriculum.delivery.teacher', 'attendance.teacher', 'lms.content.teacher', 'lms.assignments.teacher', 'examinations.marks.teacher',
            'hr.leave.self', 'hr.staff_attendance.self', 'payroll.payslips.self',
        ], array_values($resolver->schoolCapabilities($this->user('teacher@example.test'), $school)));

        // Operations desks: a demo-only, non-system role holding EXACTLY
        // one existing capability family, in the Demo School only.
        foreach (DemoAccountCatalog::OPERATIONS_DESK_ROLES as $key => $definition) {
            $desk = $this->user($definition['email']);
            $this->assertEqualsCanonicalizing($definition['capabilities'], $resolver->schoolCapabilities($desk, $school), $key);
            $this->assertSame([], $resolver->schoolCapabilities($desk, $annexe), $key);
            $this->assertFalse(Role::query()->where('key', $key)->firstOrFail()->is_system, $key);
        }
        $this->assertFalse($resolver->canInSchool($this->user('library.operator@example.test'), 'students.view', $school));
        $this->assertFalse($resolver->canInSchool($this->user('finance.officer@example.test'), 'payroll.runs.view', $school));

        // --- Identity links ------------------------------------------------
        $context = app(TenantContext::class);
        [$studentLink, $guardianLink] = $context->withSchool($school, fn () => [
            StudentGuardianAccountLink::query()->whereHas('membership', fn ($q) => $q->where('user_id', $this->user('student@example.test')->id))->first(),
            StudentGuardianAccountLink::query()->whereHas('membership', fn ($q) => $q->where('user_id', $this->user('guardian01@example.test')->id))->first(),
        ]);
        $this->assertNotNull($studentLink?->student_id);
        $this->assertNotNull($guardianLink?->guardian_id);

        // --- Tenant-scoped data volume (RLS-bound runtime connection) -------
        $this->assertSame(36, $context->withSchool($school, fn () => Student::query()->count()));
        $this->assertSame(3, $context->withSchool($annexe, fn () => Student::query()->count()));
    }

    #[Test]
    public function demo_accounts_authenticate_and_see_only_what_their_capabilities_allow(): void
    {
        $result = $this->build();
        $school = $result->school;
        $annexe = $result->secondSchool;

        $demoStudent = app(TenantContext::class)->withSchool($school, fn () => Student::query()->orderBy('student_number')->firstOrFail());

        $this->post('/login', ['email' => 'school.admin@example.test', 'password' => DemoDataBuilder::DEMO_PASSWORD])
            ->assertRedirect('/app');
        $this->assertAuthenticatedAs($this->user('school.admin@example.test'));
        $this->post('/logout');

        $this->post('/login', ['email' => 'school.admin@example.test', 'password' => 'wrong-password'])
            ->assertSessionHasErrors();
        $this->assertGuest();

        $this->assertPageStatus('school.admin@example.test', $school, '/app/students', 200);
        $this->assertPageStatus('school.admin@example.test', $school, '/app/finance/charges', 200);
        $this->assertPageStatus('school.admin@example.test', $school, '/app/finance/payments/record', 200);
        $this->assertPageStatus('school.admin@example.test', $school, "/app/students/{$demoStudent->id}", 200);

        $this->assertPageStatus('principal@example.test', $school, '/app/students', 200);
        $this->assertPageStatus('principal@example.test', $school, '/app/finance/ledger-accounts', 403);
        $this->assertPageStatus('principal@example.test', $school, '/app/finance/payments/record', 403);

        $this->assertPageStatus('hr.payroll@example.test', $school, '/app/payroll/structures', 200);
        $this->assertPageStatus('hr.payroll@example.test', $school, '/app/students', 403);

        foreach (['teacher@example.test', 'student@example.test', 'guardian01@example.test'] as $email) {
            $this->assertPageStatus($email, $school, '/app', 200);
            $this->assertPageStatus($email, $school, '/app/students', 403);
            $this->assertPageStatus($email, $school, '/app/communications', 403);
        }

        // TCH.3: the teacher reaches My Curriculum Delivery -- and nothing
        // School-wide: not the administrative delivery page, not
        // TeachingAssignment administration, not Attendance.
        $this->assertPageStatus('teacher@example.test', $school, '/app/my-curriculum-delivery', 200);
        // E33 / TCH-L1 (ADR 0063 section 43): My Attendance needs MFA, and demo
        // accounts carry no factor (no TOTP secret is ever seeded), so the demo
        // teacher gets the MfaRequired page until they enroll one.
        $this->assertPageStatus('teacher@example.test', $school, '/app/my-attendance', 403);
        $this->assertPageStatus('teacher@example.test', $school, '/app/my-learning-content', 200);
        $this->assertPageStatus('teacher@example.test', $school, '/app/my-assignments', 200);
        foreach (['/app/syllabus-delivery', '/app/teaching-assignments', '/app/attendance', '/app/timetable-schedule', '/app/learning-content', '/app/assignments'] as $page) {
            $this->assertPageStatus('teacher@example.test', $school, $page, 403);
        }

        // Phase 0L.2-1: Curriculum Coverage Analytics -- School Admin and
        // Principal only; desks and unlinked personas are refused.
        $analytics = '/app/analytics/curriculum-coverage';
        $this->assertPageStatus('school.admin@example.test', $school, $analytics, 200);
        $this->assertPageStatus('principal@example.test', $school, $analytics, 200);
        foreach (['hr.payroll@example.test', 'finance.officer@example.test', 'library.operator@example.test', 'teacher@example.test'] as $email) {
            $this->assertPageStatus($email, $school, $analytics, 403);
        }

        // Tenant isolation: the Annexe's admin cannot open a Demo School record.
        $this->assertPageStatus('annexe.admin@example.test', $annexe, '/app/students', 200);
        $this->assertPageStatus('annexe.admin@example.test', $annexe, "/app/students/{$demoStudent->id}", 404);
    }

    #[Test]
    public function the_demo_curriculum_coverage_report_shows_real_variation_and_no_annexe_data(): void
    {
        $result = $this->build();
        $readModel = app(CurriculumCoverageReadModel::class);
        $gate = app(AnalyticsReadGate::class);

        $report = $gate->read($readModel, $result->school, $this->user('principal@example.test'));
        $this->assertGreaterThan(0, $report['totals']['completed']);
        $this->assertGreaterThan(0, $report['totals']['inProgress']);
        $this->assertGreaterThan(0, $report['totals']['notStarted']);
        $percents = collect($report['offerings'])->pluck('coveragePercent')->filter()->unique();
        $this->assertGreaterThan(1, $percents->count(), 'Demo coverage should differ between Subject Offerings.');

        $annexe = $gate->read($readModel, $result->secondSchool, $this->user('annexe.admin@example.test'));
        $this->assertSame(0, $annexe['totals']['planned']);
    }

    private function assertPageStatus(string $email, School $school, string $path, int $status): void
    {
        $user = $this->user($email);

        $this->actingAs($user)->post("/app/schools/{$school->id}/activate")->assertRedirect('/app');
        $this->actingAs($user)->get($path)->assertStatus($status);

        app(TenantContext::class)->clearAllTolerantly();
        $this->flushSession();
    }
}
