<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.2 §43/§63 (mandatory per root CLAUDE.md rule 28) --
 * tenant isolation for `communication_domain_consent_events`.
 */
class CommunicationDomainConsentEventsRlsIsolationTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['communication_domain_consent_events', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createDomainConsentEvent($school, $admin, ['guardian_id' => $guardian->id]);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from communication_domain_consent_events')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_read_school_bs_consent_event(): void
    {
        $schoolA = $this->createSchool();
        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $guardianB = $this->createGuardian($schoolB);
        $eventB = $this->createDomainConsentEvent($schoolB, $adminB, ['guardian_id' => $guardianB->id]);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from communication_domain_consent_events where id = ?', [$eventB->id]);
        $this->assertCount(0, $rows);
    }

    // Note: no "cross-school UPDATE/DELETE affects zero rows" test here,
    // unlike the mutable-table RLS tests in this suite -- this table's
    // runtime role has NO update/delete privilege at all (any school
    // context), proven unconditionally by
    // the_runtime_role_cannot_update_or_delete_a_consent_event() below,
    // which already subsumes the cross-School case.

    #[Test]
    public function school_a_cannot_insert_a_consent_event_claiming_school_bs_id(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $guardianA = $this->createGuardian($schoolA);
        $schoolB = $this->createSchool();

        $rejected = false;

        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolB, $guardianA, $adminA, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolB, $guardianA, $adminA): void {
                    DB::connection('pgsql')->table('communication_domain_consent_events')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $schoolB->id,
                        'guardian_id' => $guardianA->id,
                        'student_id' => null,
                        'channel' => 'email',
                        'status' => 'granted',
                        'recorded_at' => now(),
                        'recorded_by_user_id' => $adminA->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'RLS WITH CHECK must reject a row written under School A context but claiming School B.');
    }

    #[Test]
    public function a_cross_school_guardian_reference_is_rejected_at_insert_time(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $guardianB = $this->createGuardian($this->createSchool());

        $rejected = false;

        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolA, $guardianB, $adminA, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolA, $guardianB, $adminA): void {
                    DB::connection('pgsql')->table('communication_domain_consent_events')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $schoolA->id,
                        'guardian_id' => $guardianB->id,
                        'student_id' => null,
                        'channel' => 'email',
                        'status' => 'granted',
                        'recorded_at' => now(),
                        'recorded_by_user_id' => $adminA->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'The composite FK against guardians(id, school_id) must reject a cross-School Guardian.');
    }

    #[Test]
    public function the_runtime_role_cannot_update_or_delete_a_consent_event(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $event = $this->createDomainConsentEvent($school, $admin, ['guardian_id' => $guardian->id]);

        $this->setSchool($school->id);

        try {
            DB::connection('pgsql')->transaction(function () use ($event): void {
                DB::connection('pgsql')->table('communication_domain_consent_events')->where('id', $event->id)->update(['status' => 'withdrawn']);
            });
            $this->fail('Expected a QueryException: runtime role must not be able to UPDATE consent events.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $e->getMessage());
        }

        try {
            DB::connection('pgsql')->transaction(function () use ($event): void {
                DB::connection('pgsql')->table('communication_domain_consent_events')->where('id', $event->id)->delete();
            });
            $this->fail('Expected a QueryException: runtime role must not be able to DELETE consent events.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $e->getMessage());
        }
    }
}
