<?php

namespace Tests\Feature\Postgres;

use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Identity\Application\AccountInvitationService;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\FakesEmail;
use Tests\TestCase;

/**
 * Phase 5D.3 (mandatory per root CLAUDE.md rule 28) -- tenant isolation
 * for `identity_account_invitations`.
 */
class GuardianAccountInvitationsRlsIsolationTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures, FakesEmail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeEmail();
    }

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function invite($school, $guardian, $admin, string $email)
    {
        $this->createGuardianContact($guardian, ContactType::Email, $email);

        return app(AccountInvitationService::class)->invite($school, $guardian, $admin);
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['identity_account_invitations', 'public'],
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
        $this->invite($school, $guardian, $admin, 'a@example.com');

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from identity_account_invitations')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_invitation(): void
    {
        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $guardianB = $this->createGuardian($schoolB);
        $invitationB = $this->invite($schoolB, $guardianB, $adminB, 'b@example.com');

        $schoolA = $this->createSchool();
        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from identity_account_invitations where id = ?', [$invitationB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_update_school_bs_invitation(): void
    {
        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $guardianB = $this->createGuardian($schoolB);
        $invitationB = $this->invite($schoolB, $guardianB, $adminB, 'b2@example.com');

        $schoolA = $this->createSchool();
        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            "update identity_account_invitations set status = 'revoked' where id = ?",
            [$invitationB->id],
        );

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function school_a_cannot_insert_an_invitation_claiming_school_bs_id(): void
    {
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $guardianA = $this->createGuardian($schoolA);
        $schoolB = $this->createSchool();

        $rejected = false;

        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolB, $guardianA, $adminA, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolB, $guardianA, $adminA): void {
                    DB::connection('pgsql')->table('identity_account_invitations')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $schoolB->id,
                        'guardian_id' => $guardianA->id,
                        'student_id' => null,
                        'token_hash' => hash('sha256', 'x'),
                        'destination_email_hash' => hash('sha256', 'x@example.com'),
                        'status' => 'pending',
                        'expires_at' => now()->addDays(7),
                        'invited_by_user_id' => $adminA->id,
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
        $schoolB = $this->createSchool();
        $guardianB = $this->createGuardian($schoolB);

        $rejected = false;

        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolA, $guardianB, $adminA, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolA, $guardianB, $adminA): void {
                    DB::connection('pgsql')->table('identity_account_invitations')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $schoolA->id,
                        'guardian_id' => $guardianB->id,
                        'student_id' => null,
                        'token_hash' => hash('sha256', 'y'),
                        'destination_email_hash' => hash('sha256', 'y@example.com'),
                        'status' => 'pending',
                        'expires_at' => now()->addDays(7),
                        'invited_by_user_id' => $adminA->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'The composite FK against guardians(id, school_id) must reject a cross-School reference.');
    }

    #[Test]
    public function only_one_pending_invitation_may_exist_per_guardian(): void
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $guardian = $this->createGuardian($school);
        $this->invite($school, $guardian, $admin, 'once@example.com');

        $rejected = false;

        app(TenantContext::class)->withSchool($school, function () use ($school, $guardian, $admin, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($school, $guardian, $admin): void {
                    DB::connection('pgsql')->table('identity_account_invitations')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $school->id,
                        'guardian_id' => $guardian->id,
                        'student_id' => null,
                        'token_hash' => hash('sha256', 'z'),
                        'destination_email_hash' => hash('sha256', 'z@example.com'),
                        'status' => 'pending',
                        'expires_at' => now()->addDays(7),
                        'invited_by_user_id' => $admin->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'giai_one_pending_per_guardian must reject a second pending invitation for the same Guardian.');
    }
}
