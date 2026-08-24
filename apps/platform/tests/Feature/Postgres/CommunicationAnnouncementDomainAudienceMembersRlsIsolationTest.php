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
 * Phase 5B.1 §31/§46 (mandatory per root CLAUDE.md rule 28) --
 * tenant isolation for the new
 * communication_announcement_domain_audience_members table, and the
 * new composite-tenant-FK protections it (and the widened
 * communication_announcement_recipients/communication_recipients/
 * communication_delivery_policy_decisions tables) add against
 * cross-School Student/Guardian references.
 */
class CommunicationAnnouncementDomainAudienceMembersRlsIsolationTest extends TestCase
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
            ['communication_announcement_domain_audience_members', 'public'],
        );

        $this->assertNotNull($row);
        $this->assertTrue($row->relrowsecurity);
        $this->assertTrue($row->relforcerowsecurity);
    }

    #[Test]
    public function no_school_context_sees_zero_rows(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);
        $student = $this->createStudent($school);
        $this->createDomainAudienceMember($announcement, student: $student);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from communication_announcement_domain_audience_members')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_domain_audience_member(): void
    {
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $announcementB = $this->createAnnouncement($schoolB, $creatorB);
        $studentB = $this->createStudent($schoolB);
        $rowB = $this->createDomainAudienceMember($announcementB, student: $studentB);

        $schoolA = $this->createSchool();
        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from communication_announcement_domain_audience_members where id = ?', [$rowB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_insert_a_row_claiming_school_bs_id(): void
    {
        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $announcementA = $this->createAnnouncement($schoolA, $creatorA);
        $studentA = $this->createStudent($schoolA);
        $schoolB = $this->createSchool();

        $rejected = false;

        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolB, $announcementA, $studentA, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolB, $announcementA, $studentA): void {
                    DB::connection('pgsql')->table('communication_announcement_domain_audience_members')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $schoolB->id,
                        'announcement_id' => $announcementA->id,
                        'student_id' => $studentA->id,
                        'guardian_id' => null,
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
    public function a_cross_school_student_reference_is_rejected_at_insert_time(): void
    {
        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $announcementA = $this->createAnnouncement($schoolA, $creatorA);
        $studentB = $this->createStudent($schoolB);

        $rejected = false;

        // The composite FK against students(id, school_id) must reject
        // this even though student_id alone is a real row -- its
        // school_id does not match.
        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolA, $announcementA, $studentB, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolA, $announcementA, $studentB): void {
                    DB::connection('pgsql')->table('communication_announcement_domain_audience_members')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $schoolA->id,
                        'announcement_id' => $announcementA->id,
                        'student_id' => $studentB->id,
                        'guardian_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'The composite FK against students(id, school_id) must reject a cross-School reference.');
    }

    #[Test]
    public function a_row_naming_both_student_and_guardian_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);
        $student = $this->createStudent($school);
        $guardian = $this->createGuardian($school);

        $rejected = false;

        app(TenantContext::class)->withSchool($school, function () use ($school, $announcement, $student, $guardian, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($school, $announcement, $student, $guardian): void {
                    DB::connection('pgsql')->table('communication_announcement_domain_audience_members')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $school->id,
                        'announcement_id' => $announcement->id,
                        'student_id' => $student->id,
                        'guardian_id' => $guardian->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'num_nonnulls(student_id, guardian_id) = 1 must reject a row naming both.');
    }

    #[Test]
    public function a_row_naming_neither_student_nor_guardian_is_rejected(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);

        $rejected = false;

        app(TenantContext::class)->withSchool($school, function () use ($school, $announcement, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($school, $announcement): void {
                    DB::connection('pgsql')->table('communication_announcement_domain_audience_members')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $school->id,
                        'announcement_id' => $announcement->id,
                        'student_id' => null,
                        'guardian_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'num_nonnulls(student_id, guardian_id) = 1 must reject a row naming neither.');
    }

    #[Test]
    public function communication_announcement_recipients_rejects_a_row_naming_both_a_user_and_a_student(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $student = $this->createStudent($school);

        $rejected = false;

        app(TenantContext::class)->withSchool($school, function () use ($school, $announcement, $membership, $member, $student, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($school, $announcement, $membership, $member, $student): void {
                    DB::connection('pgsql')->table('communication_announcement_recipients')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $school->id,
                        'announcement_id' => $announcement->id,
                        'school_membership_id' => $membership->id,
                        'user_id' => $member->id,
                        'student_id' => $student->id,
                        'guardian_id' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'num_nonnulls(user_id, student_id, guardian_id) = 1 must reject a row naming both a User and a Student.');
    }

    #[Test]
    public function communication_recipients_rejects_a_cross_school_guardian_reference(): void
    {
        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $threadA = $this->createThread($schoolA, $creatorA);
        $messageA = $this->createMessage($threadA, $creatorA);
        $guardianB = $this->createGuardian($schoolB);

        $rejected = false;

        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolA, $messageA, $guardianB, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolA, $messageA, $guardianB): void {
                    DB::connection('pgsql')->table('communication_recipients')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $schoolA->id,
                        'message_id' => $messageA->id,
                        'recipient_user_id' => null,
                        'recipient_guardian_id' => $guardianB->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'The composite FK against guardians(id, school_id) must reject a cross-School Guardian reference.');
    }
}
