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
 * Phase 5A.2 §12 / root CLAUDE.md rule 28 (mandatory): every new
 * Announcement table, proven at the raw-SQL level against real
 * PostgreSQL, independent of Eloquent -- mirrors
 * CommunicationsRlsIsolationTest's pattern exactly.
 */
class CommunicationAnnouncementsRlsIsolationTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private const TABLES = [
        'communication_announcements',
        'communication_announcement_audience_members',
        'communication_announcement_recipients',
    ];

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    #[Test]
    public function every_announcement_table_has_rls_enabled_and_forced(): void
    {
        foreach (self::TABLES as $table) {
            $row = DB::connection('pgsql_admin')->selectOne(
                'select relrowsecurity, relforcerowsecurity from pg_class '.
                'where relname = ? and relnamespace = ?::regnamespace',
                [$table, 'public'],
            );

            $this->assertNotNull($row, "{$table} must exist");
            $this->assertTrue($row->relrowsecurity, "{$table} must have RLS enabled");
            $this->assertTrue($row->relforcerowsecurity, "{$table} must FORCE RLS");
        }
    }

    #[Test]
    public function no_school_context_sees_zero_rows_on_every_announcement_table(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);

        $announcement = $this->createAnnouncement($school, $creator);
        $this->createAnnouncementAudienceMember($announcement, $membership);
        $this->createAnnouncementRecipient($announcement, $membership);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        foreach (self::TABLES as $table) {
            $count = DB::connection('pgsql')->selectOne("select count(*) as c from {$table}")->c;
            $this->assertSame(0, (int) $count, "{$table} must be invisible with no TenantContext");
        }
    }

    #[Test]
    public function school_a_cannot_read_school_bs_rows_on_every_announcement_table(): void
    {
        $schoolA = $this->createSchool();
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $memberB = $this->createUser();
        $membershipB = $this->createMembership($memberB, $schoolB);

        $announcementB = $this->createAnnouncement($schoolB, $creatorB);
        $audienceMemberB = $this->createAnnouncementAudienceMember($announcementB, $membershipB);
        $recipientB = $this->createAnnouncementRecipient($announcementB, $membershipB);

        $this->setSchool($schoolA->id);

        $ids = [
            'communication_announcements' => $announcementB->id,
            'communication_announcement_audience_members' => $audienceMemberB->id,
            'communication_announcement_recipients' => $recipientB->id,
        ];

        foreach ($ids as $table => $id) {
            $rows = DB::connection('pgsql')->select("select id from {$table} where id = ?", [$id]);
            $this->assertCount(0, $rows, "School A must not see School B's row in {$table}");
        }
    }

    #[Test]
    public function cross_school_writes_affect_zero_rows_on_announcement_tables(): void
    {
        $schoolA = $this->createSchool();
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $announcementB = $this->createAnnouncement($schoolB, $creatorB);

        $this->setSchool($schoolA->id);

        $this->assertSame(0, DB::connection('pgsql')->update("update communication_announcements set status = 'cancelled' where id = ?", [$announcementB->id]));
        $this->assertSame(0, DB::connection('pgsql')->delete('delete from communication_announcements where id = ?', [$announcementB->id]));
    }

    #[Test]
    public function communication_announcement_recipients_cannot_be_updated_or_deleted_by_the_runtime_role(): void
    {
        [$creator, $school] = $this->createSchoolAdmin('school_admin');
        $member = $this->createUser();
        $membership = $this->createMembership($member, $school);
        $announcement = $this->createAnnouncement($school, $creator);
        $recipient = $this->createAnnouncementRecipient($announcement, $membership);

        $this->setSchool($school->id);

        try {
            DB::connection('pgsql')->transaction(function () use ($recipient): void {
                DB::connection('pgsql')->table('communication_announcement_recipients')->where('id', $recipient->id)->update(['user_id' => $recipient->user_id]);
            });
            $this->fail('Expected a QueryException: runtime role must not be able to UPDATE announcement recipients.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $e->getMessage());
        }

        try {
            DB::connection('pgsql')->transaction(function () use ($recipient): void {
                DB::connection('pgsql')->table('communication_announcement_recipients')->where('id', $recipient->id)->delete();
            });
            $this->fail('Expected a QueryException: runtime role must not be able to DELETE announcement recipients.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('permission denied', $e->getMessage());
        }
    }

    #[Test]
    public function a_cross_school_audience_member_reference_is_rejected_at_insert_time(): void
    {
        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $memberB = $this->createUser();
        $schoolB = $this->createSchool();
        $membershipB = $this->createMembership($memberB, $schoolB);

        $announcementA = $this->createAnnouncement($schoolA, $creatorA);

        $rejected = false;

        // A composite FK against school_memberships(id, school_id) must
        // reject this even though school_membership_id alone is a real
        // row -- its school_id does not match the announcement's. The
        // insert is wrapped in its own DB::transaction() (a SAVEPOINT,
        // same reasoning as CommunicationMessageServiceTest's idempotency
        // test) so the FK violation only rolls back to that savepoint --
        // otherwise it would abort PHPUnit's own enclosing test
        // transaction and every command afterward, including
        // TenantContext::withSchool()'s own cleanup, would also fail.
        app(TenantContext::class)->withSchool($schoolA, function () use ($announcementA, $membershipB, &$rejected): void {
            try {
                DB::connection('pgsql')->transaction(function () use ($announcementA, $membershipB): void {
                    DB::connection('pgsql')->table('communication_announcement_audience_members')->insert([
                        'id' => (string) new UuidV7,
                        'school_id' => $announcementA->school_id,
                        'announcement_id' => $announcementA->id,
                        'school_membership_id' => $membershipB->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
            } catch (QueryException) {
                $rejected = true;
            }
        });

        $this->assertTrue($rejected, 'A cross-School school_membership_id must be rejected by the composite foreign key.');
    }
}
