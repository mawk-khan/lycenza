<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\Retention\DocumentRetentionEligibility;
use App\Domain\Documents\Infrastructure\Document;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesCommunicationFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.2C (E21-D5): Documents have no age of their own. Only a POSITIVELY proven
 * orphan object (managed keyspace of an existing School, older than 30 days,
 * named by no metadata row) is deleted. A failed byte delete in a purge is
 * recovered by the next orphan run.
 */
class StorageOrphanRetentionTest extends TestCase
{
    use CreatesCommunicationFixtures, CreatesTenancyFixtures;

    private string $docs;

    private string $comms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docs = (string) config('documents.disk');
        $this->comms = (string) config('communications.attachments.disk');
        Storage::fake($this->docs);
        Storage::fake($this->comms);
        config(['retention.orphan_object_days' => 30, 'retention.orphan_scan_limit' => 10000, 'retention.hold_school_ids' => []]);
    }

    /** Writes an object stamped with the TEST clock (the fake disk would otherwise use real time). */
    private function putObject(string $disk, string $path): string
    {
        Storage::disk($disk)->put($path, 'bytes');
        touch(Storage::disk($disk)->path($path), Carbon::now()->getTimestamp());

        return $path;
    }

    private function inSchool(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    private function documentRow(School $school, string $path, string $status = 'active'): Document
    {
        $employee = $this->createEmployee($school);

        return $this->inSchool($school, fn () => Document::query()->forceCreate([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'employee_id' => $employee->id, 'classification_tier' => 'internal',
            'storage_disk' => $this->docs, 'storage_path' => $path, 'original_filename' => 'x.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 5, 'uploaded_at' => now(), 'status' => $status,
        ]));
    }

    /** Make every object written so far "old" by moving the clock past the period. */
    private function age(int $days): void
    {
        $this->travelTo(Carbon::now()->addDays($days));
    }

    #[Test]
    public function nothing_is_deleted_while_unconfigured(): void
    {
        $school = $this->createSchool();
        $orphan = $this->putObject($this->docs, "schools/{$school->id}/documents/employee/x/".Str::uuid7().'.pdf');
        $this->age(400);
        config(['retention.orphan_object_days' => null]);

        $this->artisan('platform:storage-orphans-prune')->expectsOutputToContain('not configured')->assertSuccessful();
        Storage::disk($this->docs)->assertExists($orphan);
    }

    #[Test]
    public function only_a_proven_old_orphan_in_a_managed_keyspace_is_deleted(): void
    {
        $school = $this->createSchool();
        $base = "schools/{$school->id}";
        $orphan = $this->putObject($this->docs, "{$base}/documents/employee/e1/".Str::uuid7().'.pdf');
        $commsOrphan = $this->putObject($this->comms, "{$base}/communications/threads/t1/".Str::uuid7().'.pdf');
        $archived = $this->putObject($this->docs, "{$base}/documents/employee/e2/".Str::uuid7().'.pdf');
        $this->documentRow($school, $archived, 'archived');
        $hr = $this->putObject($this->docs, "{$base}/documents/hr-import/".Str::uuid7().'.pdf');
        $this->inSchool($school, fn () => DB::table('employee_documents')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'employee_id' => $this->createEmployee($school)->id, 'category' => 'other',
            'storage_disk' => $this->docs, 'storage_path' => $hr, 'original_filename' => 'h.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 5, 'uploaded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));
        // Outside the managed keyspace, or under no existing School: never touched.
        $other = $this->putObject($this->docs, "{$base}/exports/report.csv");
        $root = $this->putObject($this->docs, 'loose-file.txt');
        $unknownSchool = $this->putObject($this->docs, 'schools/'.Str::uuid7().'/documents/employee/z/'.Str::uuid7().'.pdf');

        $this->age(31);
        $young = $this->putObject($this->docs, "{$base}/documents/employee/e3/".Str::uuid7().'.pdf');

        $this->artisan('platform:storage-orphans-prune')->expectsOutputToContain('Deleted 2 orphan object(s)')->assertSuccessful();

        Storage::disk($this->docs)->assertMissing($orphan);
        Storage::disk($this->comms)->assertMissing($commsOrphan);
        foreach ([$archived, $hr, $other, $root, $unknownSchool, $young] as $kept) {
            Storage::disk($this->docs)->assertExists($kept);
        }
    }

    #[Test]
    public function the_thirty_day_floor_is_exact(): void
    {
        $school = $this->createSchool();
        $orphan = $this->putObject($this->docs, "schools/{$school->id}/documents/employee/e/".Str::uuid7().'.pdf');
        $written = Storage::disk($this->docs)->lastModified($orphan);

        $this->travelTo(Carbon::createFromTimestamp($written)->addDays(30));
        $this->artisan('platform:storage-orphans-prune')->assertSuccessful();
        Storage::disk($this->docs)->assertExists($orphan);

        $this->travelTo(Carbon::createFromTimestamp($written)->addDays(30)->addSecond());
        $this->artisan('platform:storage-orphans-prune')->assertSuccessful();
        Storage::disk($this->docs)->assertMissing($orphan);
    }

    #[Test]
    public function a_held_school_keeps_its_orphans_and_another_school_is_unaffected(): void
    {
        $held = $this->createSchool();
        $other = $this->createSchool();
        config(['retention.hold_school_ids' => [$held->id]]);
        $heldOrphan = $this->putObject($this->docs, "schools/{$held->id}/documents/employee/e/".Str::uuid7().'.pdf');
        $otherOrphan = $this->putObject($this->docs, "schools/{$other->id}/documents/employee/e/".Str::uuid7().'.pdf');
        $this->age(60);

        $this->artisan('platform:storage-orphans-prune', ['--dry-run' => true])->expectsOutputToContain('Dry run: would delete 1 orphan')->assertSuccessful();
        Storage::disk($this->docs)->assertExists($otherOrphan);

        $this->artisan('platform:storage-orphans-prune')->expectsOutputToContain('held: 1')->assertSuccessful();
        Storage::disk($this->docs)->assertExists($heldOrphan);
        Storage::disk($this->docs)->assertMissing($otherOrphan);
        $this->artisan('platform:storage-orphans-prune')->expectsOutputToContain('Deleted 0 orphan')->assertSuccessful();
    }

    #[Test]
    public function a_failed_byte_delete_during_a_purge_is_recovered_by_the_orphan_run(): void
    {
        $school = $this->createSchool();
        $this->createAcademicYear($school, ['starts_on' => '2021-04-01', 'ends_on' => '2022-03-31', 'status' => 'closed', 'code' => 'AYOLD']);
        $sender = $this->createUser();
        $thread = $this->createThread($school, $sender);
        $this->createMessage($thread, $sender, ['created_at' => '2021-06-01 06:00:00']);
        $path = $this->putObject($this->comms, "schools/{$school->id}/communications/threads/{$thread->id}/".Str::uuid7().'.pdf');
        $this->inSchool($school, fn () => DB::table('communication_attachments')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'communication_thread_id' => $thread->id, 'storage_disk' => $this->comms,
            'storage_path' => $path, 'original_filename' => 'a.pdf', 'safe_display_name' => 'a.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 5, 'checksum_sha256' => str_repeat('a', 64), 'created_by_user_id' => $sender->id, 'created_at' => now(), 'updated_at' => now(),
        ]));
        config(['retention.communications_content_years' => 3]);

        // The storage delete fails during the purge: the row still goes, the bytes stay.
        $fake = Storage::disk($this->comms);
        Storage::set($this->comms, new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function delete($paths)
            {
                return false;
            }
        });
        $this->age(400);
        $this->artisan('platform:communications-prune', ['--only' => 'content'])->expectsOutputToContain('byte delete errors: 1')->assertSuccessful();
        $this->assertFalse($this->inSchool($school, fn () => DB::table('communication_threads')->where('id', $thread->id)->exists()));
        $this->assertTrue($fake->exists($path));

        // The next orphan run removes the now-unreferenced bytes.
        Storage::set($this->comms, $fake);
        $this->artisan('platform:storage-orphans-prune')->expectsOutputToContain('Deleted 1 orphan object(s)')->assertSuccessful();
        $this->assertFalse($fake->exists($path));
    }

    #[Test]
    public function the_orphan_run_works_against_real_minio_through_s3_listing(): void
    {
        // The production path: S3 ListObjects with last-modified, real MinIO.
        config(['documents.disk' => 's3']);
        $school = $this->createSchool();
        $base = "schools/{$school->id}/documents/employee/e";
        $orphan = "{$base}/".Str::uuid7().'.pdf';
        $kept = "{$base}/".Str::uuid7().'.pdf';
        $outside = "schools/{$school->id}/elsewhere/".Str::uuid7().'.txt';
        foreach ([$orphan, $kept, $outside] as $path) {
            Storage::disk('s3')->put($path, 'bytes');
        }
        $this->inSchool($school, fn () => Document::query()->forceCreate([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'employee_id' => $this->createEmployee($school)->id,
            'classification_tier' => 'internal', 'storage_disk' => 's3', 'storage_path' => $kept, 'original_filename' => 'k.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 5, 'uploaded_at' => now(), 'status' => 'active',
        ]));

        try {
            $this->artisan('platform:storage-orphans-prune')->expectsOutputToContain('Deleted 0 orphan')->assertSuccessful();
            $this->age(31);
            $this->artisan('platform:storage-orphans-prune')->expectsOutputToContain('Deleted 1 orphan object(s)')->assertSuccessful();

            $this->assertFalse(Storage::disk('s3')->exists($orphan));
            $this->assertTrue(Storage::disk('s3')->exists($kept));
            $this->assertTrue(Storage::disk('s3')->exists($outside));
        } finally {
            Storage::disk('s3')->delete([$orphan, $kept, $outside]);
        }
    }

    #[Test]
    public function retention_code_never_deletes_document_metadata_and_documents_encodes_no_duration(): void
    {
        $files = array_merge(
            glob(app_path('Console/Commands/Prune*.php')) ?: [],
            glob(app_path('Support/Retention/*.php')) ?: [],
            glob(app_path('Domain/*/Application/Retention/*.php')) ?: [],
        );
        $this->assertNotEmpty($files);

        // E21.2D/E21.2E: the one sanctioned exception for `documents` is the parent
        // seam, which deletes only one owner's Documents inside that owner's purge.
        $seam = app_path('Domain/Documents/Application/Retention/DocumentParentRetention.php');
        $this->assertContains($seam, $files);
        $seamCode = (string) file_get_contents($seam);
        $this->assertSame(substr_count($seamCode, "table('documents')"), substr_count($seamCode, "table('documents')->where(\$"), 'every seam query is scoped to one owner');
        $this->assertStringContainsString("private const OWNER_COLUMNS = ['student' => 'student_id', 'employee' => 'employee_id'];", $seamCode, 'a closed owner map');
        $callers = [];
        exec('grep -rlF --include=*.php '.escapeshellarg('use App\\Domain\\Documents\\Application\\Retention\\DocumentParentRetention;').' '.escapeshellarg(app_path()), $callers);
        sort($callers);
        $this->assertSame([
            app_path('Domain/HR/Application/Retention/EmployeeRecordRetentionService.php'),
            app_path('Domain/Students/Application/Retention/StudentRecordRetentionService.php'),
        ], $callers, 'only the Student core purge and the Employee evidence purge use the seam');

        // E21.2E: HR's own `employee_documents` go only with their Employee, in HR's
        // evidence purge. The only other retention files naming the table are
        // read-only (the dependency catalog's closed parent list, the orphan
        // reaper's reference check).
        $hr = app_path('Domain/HR/Application/Retention/EmployeeRecordRetentionService.php');
        $naming = array_values(array_filter($files, fn (string $f) => str_contains((string) file_get_contents($f), "'employee_documents'")));
        sort($naming);
        $this->assertSame([$hr, app_path('Support/Retention/ReferencingRows.php'), app_path('Support/Retention/StorageOrphanReaper.php')], $naming);
        $this->assertStringContainsString('public function pruneEvidence(', (string) file_get_contents($hr));

        foreach (array_diff($files, [$seam]) as $file) {
            $code = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression("/table\\('(documents|employee_documents)'\\)[^;]*->(delete|update|forceDelete)\\(/s", $code, $file);
            $this->assertDoesNotMatchRegularExpression('/Document::[^;]*->(delete|forceDelete)\\(/s', $code, $file);
        }

        // Documents decides no period: no duration arithmetic, no retention config.
        foreach (glob(app_path('Domain/Documents/Application/Retention/*.php')) ?: [] as $file) {
            $this->assertDoesNotMatchRegularExpression("/(sub|add)(Years?|Months?|Days?)\\(|config\\('retention\\./", (string) file_get_contents($file), $file);
        }
    }

    #[Test]
    public function no_document_is_purge_eligible_until_its_owner_domain_decides(): void
    {
        $school = $this->createSchool();
        $document = $this->documentRow($school, $this->putObject($this->docs, "schools/{$school->id}/documents/employee/e/".Str::uuid7().'.pdf'), 'archived');
        $eligibility = app(DocumentRetentionEligibility::class);

        $this->assertFalse($eligibility->mayPurge($document));
        $this->assertStringContainsString('E21.2E', $eligibility->decidedBy($document));

        // Every owner arm of documents_exactly_one_owner_check is mapped.
        $check = DB::connection('pgsql_admin')->selectOne("select pg_get_constraintdef(oid) as d from pg_constraint where conname = 'documents_exactly_one_owner_check'")->d;
        preg_match_all('/\b(\w+)_id\b/', $check, $m);
        $this->assertEqualsCanonicalizing(array_values(array_unique($m[1])), array_keys(DocumentRetentionEligibility::OWNER_RETENTION));
    }
}
