<?php

namespace Tests\Feature\Postgres;

use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.5 §33 (mandatory per root CLAUDE.md rule 28).
 */
class CommunicationPreferencesRlsIsolationTest extends TestCase
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
            ['communication_preferences', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $this->createPreference($membership);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from communication_preferences')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_preference(): void
    {
        $schoolA = $this->createSchool();
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $memberB = $this->createUser();
        $membershipB = $this->createMembership($memberB, $schoolB);
        $preferenceB = $this->createPreference($membershipB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from communication_preferences where id = ?', [$preferenceB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_update_school_bs_preference(): void
    {
        $schoolA = $this->createSchool();
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $memberB = $this->createUser();
        $membershipB = $this->createMembership($memberB, $schoolB);
        $preferenceB = $this->createPreference($membershipB, ['preference' => 'enabled']);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            "update communication_preferences set preference = 'disabled' where id = ?",
            [$preferenceB->id],
        );

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function a_cross_school_membership_reference_is_rejected_at_insert_time(): void
    {
        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $memberB = $this->createUser();
        $schoolB = $this->createSchool();
        $membershipB = $this->createMembership($memberB, $schoolB);

        $this->setSchool($schoolA->id);

        $rejected = false;

        try {
            DB::connection('pgsql')->transaction(function () use ($schoolA, $membershipB): void {
                DB::connection('pgsql')->table('communication_preferences')->insert([
                    'id' => (string) new UuidV7,
                    'school_id' => $schoolA->id,
                    'school_membership_id' => $membershipB->id,
                    'channel' => 'email',
                    'preference' => 'enabled',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (QueryException) {
            $rejected = true;
        }

        $this->assertTrue($rejected, 'A cross-School school_membership_id must be rejected by the composite foreign key.');
    }
}
