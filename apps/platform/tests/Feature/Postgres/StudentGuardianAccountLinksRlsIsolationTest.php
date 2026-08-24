<?php

namespace Tests\Feature\Postgres;

use App\Domain\Identity\Application\AccountLinkService;
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
 * Phase 5B.2 §33/§34 (mandatory per root CLAUDE.md rule 28) -- tenant
 * isolation for `student_guardian_account_links`.
 */
class StudentGuardianAccountLinksRlsIsolationTest extends TestCase
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
            ['student_guardian_account_links', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($this->createUser(), $school);
        $guardian = $this->createGuardian($school);
        app(AccountLinkService::class)->linkGuardian($school, $guardian, $membership, $admin);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from student_guardian_account_links')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_link(): void
    {
        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $membershipB = $this->createMembership($this->createUser(), $schoolB);
        $guardianB = $this->createGuardian($schoolB);
        $linkB = app(AccountLinkService::class)->linkGuardian($schoolB, $guardianB, $membershipB, $adminB);

        $schoolA = $this->createSchool();
        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from student_guardian_account_links where id = ?', [$linkB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_insert_a_link_claiming_school_bs_id(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $membershipA = $this->createMembership($this->createUser(), $schoolA);
        $guardianA = $this->createGuardian($schoolA);
        $schoolB = $this->createSchool();

        $rejected = false;

        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolB, $membershipA, $guardianA, $adminA, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolB, $membershipA, $guardianA, $adminA): void {
                    DB::connection('pgsql')->table('student_guardian_account_links')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $schoolB->id,
                        'guardian_id' => $guardianA->id,
                        'student_id' => null,
                        'school_membership_id' => $membershipA->id,
                        'status' => 'active',
                        'linked_by_user_id' => $adminA->id,
                        'linked_at' => now(),
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
    public function a_cross_school_membership_reference_is_rejected_at_insert_time(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $membershipB = $this->createMembership($this->createUser(), $schoolB);
        $guardianA = $this->createGuardian($schoolA);

        $rejected = false;

        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolA, $membershipB, $guardianA, $adminA, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolA, $membershipB, $guardianA, $adminA): void {
                    DB::connection('pgsql')->table('student_guardian_account_links')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $schoolA->id,
                        'guardian_id' => $guardianA->id,
                        'student_id' => null,
                        'school_membership_id' => $membershipB->id,
                        'status' => 'active',
                        'linked_by_user_id' => $adminA->id,
                        'linked_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'The composite FK against school_memberships(id, school_id) must reject a cross-School reference.');
    }

    #[Test]
    public function school_a_cannot_update_school_bs_link(): void
    {
        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $membershipB = $this->createMembership($this->createUser(), $schoolB);
        $guardianB = $this->createGuardian($schoolB);
        $linkB = app(AccountLinkService::class)->linkGuardian($schoolB, $guardianB, $membershipB, $adminB);

        $schoolA = $this->createSchool();
        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            "update student_guardian_account_links set status = 'revoked' where id = ?",
            [$linkB->id],
        );

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function school_a_cannot_delete_school_bs_link(): void
    {
        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $membershipB = $this->createMembership($this->createUser(), $schoolB);
        $guardianB = $this->createGuardian($schoolB);
        $linkB = app(AccountLinkService::class)->linkGuardian($schoolB, $guardianB, $membershipB, $adminB);

        $schoolA = $this->createSchool();
        $this->setSchool($schoolA->id);

        $deleted = DB::connection('pgsql')->delete('delete from student_guardian_account_links where id = ?', [$linkB->id]);

        $this->assertSame(0, $deleted);
    }

    #[Test]
    public function only_one_active_link_may_exist_per_membership(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $membership = $this->createMembership($this->createUser(), $school);
        $guardianA = $this->createGuardian($school);
        $guardianB = $this->createGuardian($school);
        app(AccountLinkService::class)->linkGuardian($school, $guardianA, $membership, $admin);

        $rejected = false;

        app(TenantContext::class)->withSchool($school, function () use ($school, $membership, $guardianB, $admin, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($school, $membership, $guardianB, $admin): void {
                    DB::connection('pgsql')->table('student_guardian_account_links')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $school->id,
                        'guardian_id' => $guardianB->id,
                        'student_id' => null,
                        'school_membership_id' => $membership->id,
                        'status' => 'active',
                        'linked_by_user_id' => $admin->id,
                        'linked_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'sgal_one_active_per_membership must reject a second active link to the same membership.');
    }
}
