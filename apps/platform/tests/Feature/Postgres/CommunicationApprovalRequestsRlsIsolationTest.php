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
 * Phase 5A.12 §57/§89 (mandatory per root CLAUDE.md rule 28).
 */
class CommunicationApprovalRequestsRlsIsolationTest extends TestCase
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
            ['communication_approval_requests', 'public'],
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
        $this->createApprovalRequest($announcement, $creator);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from communication_approval_requests')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_request(): void
    {
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $announcementB = $this->createAnnouncement($schoolB, $creatorB);
        $requestB = $this->createApprovalRequest($announcementB, $creatorB);

        $schoolA = $this->createSchool();
        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from communication_approval_requests where id = ?', [$requestB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_insert_a_request_claiming_school_bs_id(): void
    {
        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $announcementA = $this->createAnnouncement($schoolA, $creatorA);
        $schoolB = $this->createSchool();

        $rejected = false;

        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolB, $announcementA, $creatorA, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolB, $announcementA, $creatorA): void {
                    DB::connection('pgsql')->table('communication_approval_requests')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $schoolB->id,
                        'announcement_id' => $announcementA->id,
                        'requested_by_user_id' => $creatorA->id,
                        'requested_at' => now(),
                        'fingerprint' => str_repeat('a', 64),
                        'snapshot' => json_encode([]),
                        'status' => 'pending',
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
    public function a_cross_school_announcement_reference_is_rejected_at_insert_time(): void
    {
        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $announcementB = $this->createAnnouncement($schoolB, $creatorB);

        $rejected = false;

        // The composite FK against communication_announcements(id,
        // school_id) must reject this even though announcement_id
        // alone is a real row -- its school_id does not match.
        app(TenantContext::class)->withSchool($schoolA, function () use ($schoolA, $announcementB, $creatorA, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($schoolA, $announcementB, $creatorA): void {
                    DB::connection('pgsql')->table('communication_approval_requests')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $schoolA->id,
                        'announcement_id' => $announcementB->id,
                        'requested_by_user_id' => $creatorA->id,
                        'requested_at' => now(),
                        'fingerprint' => str_repeat('a', 64),
                        'snapshot' => json_encode([]),
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'The composite FK against communication_announcements(id, school_id) must reject a cross-School reference.');
    }

    #[Test]
    public function school_a_cannot_update_school_bs_request(): void
    {
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $announcementB = $this->createAnnouncement($schoolB, $creatorB);
        $requestB = $this->createApprovalRequest($announcementB, $creatorB);

        $schoolA = $this->createSchool();
        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->update(
            "update communication_approval_requests set status = 'approved' where id = ?",
            [$requestB->id],
        );

        $this->assertSame(0, $updated);
    }

    #[Test]
    public function only_one_pending_request_may_exist_per_announcement(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $announcement = $this->createAnnouncement($school, $creator);
        $this->createApprovalRequest($announcement, $creator, ['status' => 'pending']);

        $rejected = false;

        // Wrapped in its own DB::transaction() (a SAVEPOINT) so the
        // partial-unique-index violation only rolls back to that
        // savepoint -- otherwise it would abort PHPUnit's own
        // enclosing test transaction and TenantContext::withSchool()'s
        // own cleanup afterward would also fail.
        app(TenantContext::class)->withSchool($school, function () use ($announcement, $creator, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($announcement, $creator): void {
                    DB::connection('pgsql')->table('communication_approval_requests')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $announcement->school_id,
                        'announcement_id' => $announcement->id,
                        'requested_by_user_id' => $creator->id,
                        'requested_at' => now(),
                        'fingerprint' => str_repeat('b', 64),
                        'snapshot' => json_encode([]),
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'The partial unique index must reject a second pending request for the same announcement.');
    }
}
