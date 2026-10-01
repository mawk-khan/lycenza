<?php

namespace Tests\Feature\Documents;

use App\Support\Retention\StorageOrphanReaper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.2C (E21-D5): the orphan run against an upload whose metadata has not
 * committed yet, in a real separate process holding the transaction open.
 * The orphan run cannot see the uncommitted row. It keeps the object because
 * the object is younger than the 30-day floor (UUIDv7 keys are never
 * reused). Once the row commits, metadata and bytes agree.
 */
class DocumentsRetentionConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?string $schoolId = null;

    private ?string $startedAt = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startedAt = now()->subSecond()->toDateTimeString();
        config(['documents.disk' => 'local', 'communications.attachments.disk' => 'local', 'retention.orphan_object_days' => 30, 'retention.hold_school_ids' => []]);
    }

    protected function tearDown(): void
    {
        if ($this->schoolId !== null) {
            Storage::disk('local')->deleteDirectory("schools/{$this->schoolId}");
            DB::connection('pgsql_admin')->table('documents')->where('school_id', $this->schoolId)->delete();
            $this->deleteSchoolAsAdmin($this->schoolId);
        }
        DB::connection('pgsql_admin')->table('users')->where('created_at', '>=', $this->startedAt)->whereNotIn('id', DB::connection('pgsql_admin')->table('platform_role_assignments')->select('user_id'))->delete();

        parent::tearDown();
    }

    #[Test]
    public function an_uncommitted_upload_is_never_reaped(): void
    {
        $school = $this->createSchool();
        $this->schoolId = $school->id;
        $employee = $this->createEmployee($school);
        $path = "schools/{$school->id}/documents/employee/{$employee->id}/".Str::uuid7().'.pdf';
        Storage::disk('local')->put($path, 'bytes');

        $dir = sys_get_temp_dir().'/race_'.bin2hex(random_bytes(8));
        mkdir($dir);
        $holder = new Process(['php', __DIR__.'/../../Support/document-retention-op.php', 'insert-document', $school->id, $employee->id, 'local', $path], null, ['CONCURRENCY_HOLD_DIR' => $dir]);
        $holder->setTimeout(120);

        try {
            $holder->start();
            $deadline = microtime(true) + 60;
            while (! file_exists($dir.'/acted')) {
                $this->assertTrue($holder->isRunning() && microtime(true) < $deadline, 'holder never reached its held insert: '.$holder->getErrorOutput());
                usleep(2_000);
            }

            // The row is uncommitted and invisible here. Only the age floor protects the object.
            $this->assertFalse(DB::connection('pgsql_admin')->table('documents')->where('storage_path', $path)->exists());
            $result = app(StorageOrphanReaper::class)->forSchool($school, Carbon::now()->subDays(30), 10000, false);
            $this->assertSame(0, $result['deleted']);
            $this->assertTrue(Storage::disk('local')->exists($path));
        } finally {
            touch($dir.'/release');
            $holder->wait();
            @unlink($dir.'/acted');
            @unlink($dir.'/release');
            @rmdir($dir);
        }

        $this->assertSame('inserted', trim($holder->getOutput()));
        $this->assertTrue(DB::connection('pgsql_admin')->table('documents')->where('storage_path', $path)->exists());
        $this->assertTrue(Storage::disk('local')->exists($path));
    }
}
