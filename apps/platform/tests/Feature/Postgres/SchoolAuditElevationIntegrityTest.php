<?php

namespace Tests\Feature\Postgres;

use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.1A (ADR 0076 §24, V-4): a School audit event recorded under an
 * elevation must reference an elevation INTO THAT SAME School, proven by
 * PostgreSQL itself (composite `(elevation_id, school_id)` key), not only by
 * AuditRecorder. Raw SQL as the runtime role, inside each School's tenant
 * context, exactly as the audit writer runs.
 */
class SchoolAuditElevationIntegrityTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function elevationInto(School $school, User $actor): string
    {
        $id = (string) Str::uuid7();
        DB::table('school_elevations')->insert([
            'id' => $id,
            'actor_user_id' => $actor->id,
            'school_id' => $school->id,
            'authority_type' => 'platform',
            'reason_code' => 'operational_support',
            'status' => 'active',
            'started_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        return $id;
    }

    private function auditIn(School $school, ?string $elevationId): void
    {
        app(TenantContext::class)->withSchool($school, fn () => DB::table('school_audit_events')->insert([
            'id' => (string) Str::uuid7(),
            'school_id' => $school->id,
            'event_type' => 'test.elevated_action',
            'elevation_id' => $elevationId,
            'occurred_at' => now(),
        ]));
    }

    #[Test]
    public function an_audit_event_may_reference_an_elevation_into_its_own_school_or_none(): void
    {
        $schoolB = $this->createSchool();
        $elevationIntoB = $this->elevationInto($schoolB, $this->createUser());

        $this->auditIn($schoolB, $elevationIntoB);
        $this->auditIn($schoolB, null);

        $this->assertSame(1, app(TenantContext::class)->withSchool($schoolB, fn () => DB::table('school_audit_events')->where('elevation_id', $elevationIntoB)->count()));
    }

    #[Test]
    public function postgresql_rejects_an_audit_event_referencing_another_schools_elevation(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $elevationIntoB = $this->elevationInto($schoolB, $this->createUser());

        try {
            DB::transaction(fn () => $this->auditIn($schoolA, $elevationIntoB));
            $this->fail('A School A audit event must not reference an elevation into School B.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('school_audit_events_elevation_school_foreign', $e->getMessage());
        }

        $this->assertSame(0, app(TenantContext::class)->withSchool($schoolA, fn () => DB::table('school_audit_events')->where('elevation_id', $elevationIntoB)->count()));
    }

    #[Test]
    public function the_reference_is_the_composite_key_and_elevations_expose_it(): void
    {
        $admin = DB::connection('pgsql_admin');

        $this->assertSame(
            'FOREIGN KEY (elevation_id, school_id) REFERENCES school_elevations(id, school_id) ON DELETE RESTRICT',
            $admin->scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'school_audit_events_elevation_school_foreign'"),
        );
        $this->assertSame('UNIQUE (id, school_id)', $admin->scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'school_elevations_id_school_unique'"));
        $this->assertNull($admin->scalar("SELECT conname FROM pg_constraint WHERE conname = 'school_audit_events_elevation_id_foreign'"), 'the single-column reference is gone');
    }
}
