<?php

namespace Tests\Feature\Demo;

use App\Domain\Identity\Infrastructure\StudentGuardianAccountLink;
use App\Domain\Students\Infrastructure\Student;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
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
            'platform.admin@example.test', 'school.admin@example.test', 'principal@example.test',
            'hr.payroll@example.test', 'multi.school@example.test', 'annexe.admin@example.test',
            'teacher@example.test', 'student@example.test', 'guardian01@example.test',
        ], $emails);

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

        // --- Capability separation (real seeded roles) --------------------
        $admin = $this->user('school.admin@example.test');
        $principal = $this->user('principal@example.test');
        $hrPayroll = $this->user('hr.payroll@example.test');

        $this->assertTrue($resolver->canInSchool($admin, 'finance.ledger.view', $school));
        $this->assertTrue($resolver->canInSchool($admin, 'students.manage', $school));
        $this->assertFalse($resolver->canInSchool($admin, 'payroll.compensation.sensitive.view', $school));
        $this->assertFalse($resolver->canInSchool($admin, 'students.view', $annexe));

        $this->assertTrue($resolver->canInSchool($principal, 'students.view', $school));
        $this->assertFalse($resolver->canInSchool($principal, 'finance.ledger.view', $school));
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

        foreach (['teacher@example.test', 'student@example.test', 'guardian01@example.test'] as $email) {
            $this->assertSame([], $resolver->schoolCapabilities($this->user($email), $school), $email);
        }

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
        $this->assertPageStatus('school.admin@example.test', $school, "/app/students/{$demoStudent->id}", 200);

        $this->assertPageStatus('principal@example.test', $school, '/app/students', 200);
        $this->assertPageStatus('principal@example.test', $school, '/app/finance/ledger-accounts', 403);

        $this->assertPageStatus('hr.payroll@example.test', $school, '/app/payroll/structures', 200);
        $this->assertPageStatus('hr.payroll@example.test', $school, '/app/students', 403);

        foreach (['teacher@example.test', 'student@example.test', 'guardian01@example.test'] as $email) {
            $this->assertPageStatus($email, $school, '/app', 200);
            $this->assertPageStatus($email, $school, '/app/students', 403);
            $this->assertPageStatus($email, $school, '/app/communications', 403);
        }

        // Tenant isolation: the Annexe's admin cannot open a Demo School record.
        $this->assertPageStatus('annexe.admin@example.test', $annexe, '/app/students', 200);
        $this->assertPageStatus('annexe.admin@example.test', $annexe, "/app/students/{$demoStudent->id}", 404);
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
