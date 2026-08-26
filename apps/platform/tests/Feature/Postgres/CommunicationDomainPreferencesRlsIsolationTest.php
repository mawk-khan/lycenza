<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5D.2 §43/§63 (mandatory per root CLAUDE.md rule 28) --
 * tenant isolation for `communication_domain_preferences`.
 */
class CommunicationDomainPreferencesRlsIsolationTest extends TestCase
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
            ['communication_domain_preferences', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createDomainPreference($school, ['guardian_id' => $guardian->id]);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from communication_domain_preferences')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_read_school_bs_preference_row(): void
    {
        $schoolA = $this->createSchool();
        [, $schoolB] = $this->createSchoolAdmin('school_admin');
        $guardianB = $this->createGuardian($schoolB);
        $preferenceB = $this->createDomainPreference($schoolB, ['guardian_id' => $guardianB->id]);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from communication_domain_preferences where id = ?', [$preferenceB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function cross_school_writes_affect_zero_rows(): void
    {
        $schoolA = $this->createSchool();
        [, $schoolB] = $this->createSchoolAdmin('school_admin');
        $guardianB = $this->createGuardian($schoolB);
        $preferenceB = $this->createDomainPreference($schoolB, ['guardian_id' => $guardianB->id]);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update(
            "update communication_domain_preferences set preference = 'disabled' where id = ?",
            [$preferenceB->id],
        ));
    }

    #[Test]
    public function a_cross_school_guardian_reference_is_rejected_at_insert_time(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $guardianB = $this->createGuardian($this->createSchool());

        $rejected = false;

        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolA, $guardianB, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolA, $guardianB): void {
                    DB::connection('pgsql')->table('communication_domain_preferences')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $schoolA->id,
                        'guardian_id' => $guardianB->id,
                        'student_id' => null,
                        'channel' => 'email',
                        'preference' => 'enabled',
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
    public function only_one_current_preference_row_may_exist_per_guardian_and_channel(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->createDomainPreference($school, ['guardian_id' => $guardian->id, 'channel' => 'email']);

        $rejected = false;

        app(TenantContext::class)->withSchool($school, function () use ($school, $guardian, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($school, $guardian): void {
                    DB::connection('pgsql')->table('communication_domain_preferences')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $school->id,
                        'guardian_id' => $guardian->id,
                        'student_id' => null,
                        'channel' => 'email',
                        'preference' => 'disabled',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (UniqueConstraintViolationException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'cdp_one_current_per_guardian_channel must reject a second row for the same Guardian+channel.');
    }
}
