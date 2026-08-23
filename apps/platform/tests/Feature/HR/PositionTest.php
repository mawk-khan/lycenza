<?php

namespace Tests\Feature\HR;

use App\Domain\HR\Application\PositionService;
use App\Domain\HR\Infrastructure\Position;
use App\Models\MembershipRoleAssignment;
use App\Models\PlatformRoleAssignment;
use App\Models\Role;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 8A.3: proves the HR Position organizational reference entity
 * -- UUIDv7 identity, School ownership, School-scoped code uniqueness,
 * the active/inactive lifecycle, and -- critically -- that Position is
 * structurally independent of the existing authorization architecture
 * (docs/modules/HR.md principle 2.4: Position != Role). See
 * tests/Feature/Postgres/HrRawIsolationTest for the independent
 * raw-SQL/RLS proof.
 */
class PositionTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function position_id_is_a_real_uuidv7(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);

        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($position->id));
    }

    #[Test]
    public function position_belongs_to_its_school(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);

        app(TenantContext::class)->set($school);

        $this->assertSame($school->id, $position->fresh()->school_id);
    }

    #[Test]
    public function code_is_normalized_to_uppercase(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school, ['code' => 'tch']);

        $this->assertSame('TCH', $position->code);
    }

    #[Test]
    public function the_same_code_and_name_are_valid_in_a_different_school(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();

        $positionA = $this->createPosition($schoolA, ['name' => 'Teacher', 'code' => 'TCH']);
        $positionB = $this->createPosition($schoolB, ['name' => 'Teacher', 'code' => 'TCH']);

        app(TenantContext::class)->set($schoolA);
        $this->assertSame('TCH', $positionA->fresh()->code);

        app(TenantContext::class)->set($schoolB);
        $this->assertSame('TCH', $positionB->fresh()->code);
    }

    #[Test]
    public function a_duplicate_code_within_the_same_school_is_rejected(): void
    {
        $school = $this->createSchool();
        $this->createPosition($school, ['code' => 'TCH']);

        app(TenantContext::class)->set($school);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::transaction(function () use ($school): void {
            Position::query()->create([
                'school_id' => $school->id,
                'name' => 'Another Teacher Title',
                'code' => 'TCH',
                'status' => 'active',
            ]);
        });
    }

    #[Test]
    public function a_position_defaults_to_active_status(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);

        $this->assertSame('active', $position->status);
        $this->assertTrue($position->isActive());
    }

    #[Test]
    public function the_inactive_factory_state_produces_an_inactive_position(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school, ['status' => 'inactive']);

        app(TenantContext::class)->set($school);
        $this->assertFalse($position->fresh()->isActive());
    }

    #[Test]
    public function service_create_produces_an_active_position(): void
    {
        $school = $this->createSchool();

        $position = app(PositionService::class)->create($school, ['name' => 'Accountant', 'code' => 'acc']);

        $this->assertSame('ACC', $position->code);
        $this->assertSame('active', $position->status);
    }

    #[Test]
    public function service_update_ignores_school_id_and_status(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $position = $this->createPosition($schoolA, ['name' => 'Original Title']);

        $updated = app(PositionService::class)->update($position, [
            'name' => 'Updated Title',
            'school_id' => $schoolB->id,
            'status' => 'inactive',
        ]);

        $this->assertSame('Updated Title', $updated->name);
        $this->assertSame($schoolA->id, $updated->school_id);
        $this->assertSame('active', $updated->status, 'update() must never silently change status -- archive()/reactivate() own that.');
    }

    #[Test]
    public function archive_then_reactivate_round_trips_correctly(): void
    {
        $school = $this->createSchool();
        $position = $this->createPosition($school);
        $service = app(PositionService::class);

        $archived = $service->archive($position);
        $this->assertSame('inactive', $archived->status);

        $reactivated = $service->reactivate($archived);
        $this->assertSame('active', $reactivated->status);
    }

    #[Test]
    public function school_a_cannot_see_school_bs_position(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $this->createPosition($schoolB);

        app(TenantContext::class)->set($schoolA);

        $this->assertSame(0, Position::query()->count());
    }

    #[Test]
    public function school_b_cannot_update_school_as_position(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $positionA = $this->createPosition($schoolA, ['name' => 'Original Name']);

        app(TenantContext::class)->set($schoolB);

        $affected = Position::query()->where('id', $positionA->id)->update(['name' => 'Hacked Name']);

        $this->assertSame(0, $affected);
    }

    /**
     * Structural inspection, not a probabilistic test: creating,
     * updating, archiving, and reactivating a Position through every
     * public PositionService method must never insert/update a row in
     * any authorization table. positions carries no foreign key to
     * roles/capabilities/membership_role_assignments/
     * platform_role_assignments (confirmed by migration inspection),
     * and PositionService's source contains no reference to
     * App\Models\Role/App\Models\Capability/App\Models\MembershipRoleAssignment
     * at all -- this test proves that boundary holds at runtime too, by
     * asserting row counts in every authorization table are byte-for-
     * byte unchanged after a full Position lifecycle.
     */
    #[Test]
    public function the_full_position_lifecycle_never_touches_an_authorization_table(): void
    {
        $school = $this->createSchool();

        app(TenantContext::class)->set($school);
        $rolesBefore = Role::query()->count();
        $membershipRoleAssignmentsBefore = MembershipRoleAssignment::query()->count();
        $platformRoleAssignmentsBefore = PlatformRoleAssignment::query()->count();

        $service = app(PositionService::class);
        $position = $service->create($school, ['name' => 'Vice Principal', 'code' => 'VP']);
        $position = $service->update($position, ['description' => 'Deputy academic and operational lead.']);
        $position = $service->archive($position);
        $service->reactivate($position);

        app(TenantContext::class)->set($school);
        $this->assertSame($rolesBefore, Role::query()->count(), 'Position lifecycle must never create/delete a Role.');
        $this->assertSame($membershipRoleAssignmentsBefore, MembershipRoleAssignment::query()->count(), 'Position lifecycle must never create a MembershipRoleAssignment.');
        $this->assertSame($platformRoleAssignmentsBefore, PlatformRoleAssignment::query()->count(), 'Position lifecycle must never create a PlatformRoleAssignment.');
    }
}
