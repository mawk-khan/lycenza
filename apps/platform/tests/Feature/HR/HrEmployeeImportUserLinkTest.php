<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\EmployeeImportService;
use App\Domain\HR\Infrastructure\Employee;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.1 (ADR 0063 section 6): Employee import is no bypass around the
 * hardened link. A row's `user_id` goes through EmployeeService::create(),
 * whose link primitive is the one EmployeeService::linkUser() uses -- so
 * an invited, suspended, disabled or other-School User fails the row
 * (redacted, nothing created), and an accepted link is audited.
 */
class HrEmployeeImportUserLinkTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function import(School $school, string $userId): array
    {
        $result = app(EmployeeImportService::class)->import($school, $this->fullHrActor($school), [
            ['full_name' => 'Imported Person', 'user_id' => $userId],
        ]);

        return [$result->rows[0], $result];
    }

    private function member(School $school, string $status = 'active'): User
    {
        $user = $this->createUser();
        $this->createMembership($user, $school, $status);

        return $user;
    }

    private function employeeCount(School $school): int
    {
        return app(TenantContext::class)->withSchool($school, fn () => Employee::query()->count());
    }

    #[Test]
    public function an_import_link_to_an_active_member_succeeds_and_is_audited(): void
    {
        $school = $this->createSchool();
        $user = $this->member($school);

        [$row] = $this->import($school, $user->id);

        $this->assertSame('created', $row->status);
        app(TenantContext::class)->set($school);
        $this->assertSame($user->id, Employee::query()->find($row->employeeId)->user_id);
        $this->assertTrue(SchoolAuditEvent::query()->where('event_type', 'employee.user_linked')->where('subject_id', $row->employeeId)->exists());
    }

    #[Test]
    public function an_import_link_to_an_invited_suspended_disabled_or_other_school_user_fails_the_row(): void
    {
        $school = $this->createSchool();
        $disabled = $this->member($school);
        DB::table('users')->where('id', $disabled->id)->update(['is_disabled' => true, 'disabled_at' => now()]);

        $candidates = [
            'invited' => $this->member($school, 'invited'),
            'suspended' => $this->member($school, 'suspended'),
            'disabled' => $disabled,
            'other school' => $this->member($this->createSchool()),
        ];

        foreach ($candidates as $label => $user) {
            [$row] = $this->import($school, $user->id);

            $this->assertSame('failed', $row->status, $label);
            $this->assertSame('user_id', $row->errors[0]['field'], $label);
            $this->assertSame('validation', $row->errors[0]['code'], $label);
        }

        $this->assertSame(0, $this->employeeCount($school), 'No Employee is created for a refused link.');
    }
}
