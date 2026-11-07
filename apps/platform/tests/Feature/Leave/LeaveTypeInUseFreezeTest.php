<?php

namespace Tests\Feature\Leave;

use App\Domain\Leave\Application\Exceptions\LeaveException;
use App\Domain\Leave\Application\LeaveRequestService;
use App\Domain\Leave\Application\LeaveTypeService;
use App\Domain\Leave\Infrastructure\LeaveType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Leave\Concerns\CreatesLeaveFixtures;
use Tests\TestCase;

/**
 * HR.R1-C11 (C10-N1): `is_paid` on a leave type is a classification, not a payment
 * state. `LeaveCoverageReader` reads it live for Payroll's absence evidence, so history
 * depends on it staying put. `leave_types_freeze` froze it once a policy or ledger row
 * existed, but an untracked type has neither, so approved leave could be reclassified
 * from paid to unpaid after the fact. A request is use.
 */
class LeaveTypeInUseFreezeTest extends TestCase
{
    use CreatesLeaveFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-05 10:00:00');
    }

    /** @return array{0: array, 1: LeaveType} */
    private function untrackedWithApprovedLeave(string $code = 'UNT'): array
    {
        $w = $this->leaveWorld();
        $this->workingWeek($w['school'], $w['admin']);
        $type = $this->leaveType($w['school'], $w['admin'], $code, true, false);
        $request = $this->submitLeave($w, '2026-10-12', '2026-10-12', 'full', null, null, $type);
        app(LeaveRequestService::class)->approve($w['school'], $request->id, $w['admin']);

        return [$w, $type];
    }

    private function field(array $w, LeaveType $type, string $column): mixed
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => LeaveType::query()->whereKey($type->id)->value($column));
    }

    #[Test]
    public function a_type_with_a_request_keeps_its_rules_through_the_service(): void
    {
        [$w, $type] = $this->untrackedWithApprovedLeave();

        foreach ([['is_paid' => false], ['allows_half_day' => false], ['tracks_balance' => true]] as $change) {
            try {
                app(LeaveTypeService::class)->update($w['school'], $type->id, $change, $w['admin']);
                $this->fail('A leave type in use keeps its rules.');
            } catch (LeaveException $e) {
                $this->assertSame('LEAVE_TYPE_IN_USE', $e->errorCode());
            }
        }
        $this->assertTrue((bool) $this->field($w, $type, 'is_paid'));
    }

    #[Test]
    public function the_runtime_role_cannot_change_it_either(): void
    {
        [$w, $type] = $this->untrackedWithApprovedLeave();

        try {
            DB::transaction(fn () => app(TenantContext::class)->withSchool($w['school'], fn () => DB::table('leave_types')->where('id', $type->id)->update(['is_paid' => false])));
            $this->fail('The database must refuse it.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('leave_type_in_use', $e->getMessage());
        }
    }

    #[Test]
    public function a_new_type_stays_editable_and_its_name_always_is(): void
    {
        $w = $this->leaveWorld();
        $fresh = $this->leaveType($w['school'], $w['admin'], 'NEW', true, false);

        $this->assertFalse(app(LeaveTypeService::class)->update($w['school'], $fresh->id, ['is_paid' => false], $w['admin'])->is_paid);

        [$used, $type] = $this->untrackedWithApprovedLeave('UN2');
        $this->assertSame('Renamed', app(LeaveTypeService::class)->update($used['school'], $type->id, ['name' => 'Renamed'], $used['admin'])->name);
    }

    #[Test]
    public function another_schools_request_does_not_freeze_this_schools_type(): void
    {
        [$other, $theirs] = $this->untrackedWithApprovedLeave('UN3');
        $w = $this->leaveWorld();
        $mine = $this->leaveType($w['school'], $w['admin'], 'UN3', true, false);

        $this->assertFalse(app(LeaveTypeService::class)->update($w['school'], $mine->id, ['is_paid' => false], $w['admin'])->is_paid);
        $this->assertTrue((bool) $this->field($other, $theirs, 'is_paid'));
    }

    #[Test]
    public function the_runtime_role_is_confined_to_its_school_and_gains_nothing(): void
    {
        [$w, $type] = $this->untrackedWithApprovedLeave('UN4');
        $foreign = $this->leaveWorld();

        $role = DB::selectOne('select current_user as name, r.rolsuper, r.rolbypassrls from pg_roles r where r.rolname = current_user');
        $this->assertSame(['school_os_app', false, false], [$role->name, $role->rolsuper, $role->rolbypassrls]);

        $change = fn () => DB::table('leave_types')->where('id', $type->id)->update(['is_paid' => false]);
        $this->assertSame(0, app(TenantContext::class)->withSchool($foreign['school'], $change), 'another School does not see the row');
        $this->assertSame(0, $change(), 'no School context sees nothing');

        $this->assertFalse(DB::selectOne("select has_table_privilege('school_os_app', 'leave_types', 'DELETE') as allowed")->allowed);
        $this->assertTrue((bool) $this->field($w, $type, 'is_paid'));
    }
}
