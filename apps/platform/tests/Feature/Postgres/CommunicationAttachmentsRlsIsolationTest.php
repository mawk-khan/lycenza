<?php

namespace Tests\Feature\Postgres;

use App\Domain\Communications\Application\CommunicationAttachmentService;
use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 5A.6 §40 (mandatory per root CLAUDE.md rule 28). Mirrors
 * CommunicationDeliveryPolicyDecisionsRlsIsolationTest's raw-SQL proof
 * pattern for `communication_attachments`.
 */
class CommunicationAttachmentsRlsIsolationTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    private function attach($school, $announcement, $creator): CommunicationAttachment
    {
        return app(CommunicationAttachmentService::class)->upload(
            $announcement,
            $creator,
            UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        );
    }

    #[Test]
    public function the_table_has_rls_enabled_and_forced(): void
    {
        $row = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class '.
            'where relname = ? and relnamespace = ?::regnamespace',
            ['communication_attachments', 'public'],
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
        $this->attach($school, $announcement, $creator);

        DB::connection('pgsql')->statement('RESET '.TenantRls::SESSION_VAR);

        $count = DB::connection('pgsql')->selectOne('select count(*) as c from communication_attachments')->c;
        $this->assertSame(0, (int) $count);
    }

    #[Test]
    public function school_a_cannot_select_school_bs_attachment(): void
    {
        $schoolA = $this->createSchool();
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $announcementB = $this->createAnnouncement($schoolB, $creatorB);
        $attachmentB = $this->attach($schoolB, $announcementB, $creatorB);

        $this->setSchool($schoolA->id);

        $rows = DB::connection('pgsql')->select('select id from communication_attachments where id = ?', [$attachmentB->id]);
        $this->assertCount(0, $rows);
    }

    #[Test]
    public function school_a_cannot_insert_an_attachment_row_claiming_school_bs_id(): void
    {
        $schoolA = $this->createSchool();
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $announcementB = $this->createAnnouncement($schoolB, $creatorB);

        $this->setSchool($schoolA->id);

        try {
            // Wrapped in its own transaction() so Laravel issues a
            // SAVEPOINT (we're already inside DatabaseTransactions'
            // outer transaction) -- without it, the expected Postgres
            // error would leave the whole test transaction aborted for
            // every statement after this one, including tearDown()'s
            // own TenantContext-clearing RESET.
            DB::connection('pgsql')->transaction(function () use ($schoolB, $announcementB, $creatorB): void {
                DB::connection('pgsql')->table('communication_attachments')->insert([
                    'id' => (string) new UuidV7,
                    'school_id' => $schoolB->id,
                    'communication_announcement_id' => $announcementB->id,
                    'communication_message_id' => null,
                    'storage_disk' => 'local',
                    'storage_path' => 'communications/attachments/forged.pdf',
                    'original_filename' => 'forged.pdf',
                    'safe_display_name' => 'forged.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 100,
                    'checksum_sha256' => str_repeat('a', 64),
                    'created_by_user_id' => $creatorB->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
            $this->fail('Expected a QueryException: RLS must reject an INSERT claiming a foreign school_id.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('new row violates row-level security policy', $e->getMessage());
        }
    }

    #[Test]
    public function school_a_cannot_update_or_delete_school_bs_attachment(): void
    {
        $schoolA = $this->createSchool();
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');
        $announcementB = $this->createAnnouncement($schoolB, $creatorB);
        $attachmentB = $this->attach($schoolB, $announcementB, $creatorB);

        $this->setSchool($schoolA->id);

        $updated = DB::connection('pgsql')->table('communication_attachments')
            ->where('id', $attachmentB->id)
            ->update(['safe_display_name' => 'renamed.pdf']);
        $this->assertSame(0, $updated);

        $deleted = DB::connection('pgsql')->table('communication_attachments')
            ->where('id', $attachmentB->id)
            ->delete();
        $this->assertSame(0, $deleted);

        // Still exists, untouched -- re-checked on the SAME 'pgsql'
        // connection/session under School B's own context (not
        // 'pgsql_admin', a genuinely separate session/connection that
        // cannot see this test's still-open, uncommitted transaction
        // under DatabaseTransactions -- a cross-connection read would
        // find nothing regardless of whether the row survived).
        $this->setSchool($schoolB->id);
        $stillExists = DB::connection('pgsql')->table('communication_attachments')
            ->where('id', $attachmentB->id)->exists();
        $this->assertTrue($stillExists);
    }

    #[Test]
    public function a_forged_cross_school_announcement_reference_is_rejected_by_the_composite_foreign_key(): void
    {
        [$creatorA, $schoolA] = $this->createSchoolAdmin('school_admin');
        $announcementA = $this->createAnnouncement($schoolA, $creatorA);
        [$creatorB, $schoolB] = $this->createSchoolAdmin('school_admin');

        $this->setSchool($schoolB->id);

        try {
            // schoolB's own row, but pointed at School A's Announcement
            // id -- the composite FK (id, school_id) on
            // communication_announcements has no row matching
            // (announcementA.id, schoolB.id), so this must fail at the
            // database layer regardless of RLS. Wrapped in its own
            // transaction() (SAVEPOINT) for the same reason as the RLS
            // INSERT-violation test above.
            DB::connection('pgsql')->transaction(function () use ($schoolB, $announcementA, $creatorB): void {
                DB::connection('pgsql')->table('communication_attachments')->insert([
                    'id' => (string) new UuidV7,
                    'school_id' => $schoolB->id,
                    'communication_announcement_id' => $announcementA->id,
                    'communication_message_id' => null,
                    'storage_disk' => 'local',
                    'storage_path' => 'communications/attachments/forged2.pdf',
                    'original_filename' => 'forged2.pdf',
                    'safe_display_name' => 'forged2.pdf',
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 100,
                    'checksum_sha256' => str_repeat('b', 64),
                    'created_by_user_id' => $creatorB->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
            $this->fail('Expected a QueryException: composite FK must reject a cross-school announcement reference.');
        } catch (QueryException $e) {
            $this->assertStringContainsStringIgnoringCase('violates foreign key constraint', $e->getMessage());
        }
    }
}
