<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Application\Import\StudentGuardianImport;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * SIS.EXP-5: REQUIRED real-concurrency proof -- two genuinely separate OS
 * processes commit the SAME signed preview at once (a double click, a
 * retried request). The contender is observed blocked, then replays the
 * holder's batch: one batch, one set of Students, never two.
 * Committed fixtures (no DatabaseTransactions); removed in tearDown.
 */
class StudentGuardianImportConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_concurrent_double_commit_of_one_preview_imports_exactly_once(): void
    {
        [$admin, $this->school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'MAIN']);
        $year = $this->createAcademicYear($this->school, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $this->createSection($year, $campus, $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]), ['name' => 'A', 'code' => 'A']);

        $import = app(StudentGuardianImport::class);
        $header = StudentGuardianImport::columns();
        $line = fn (string $first, string $roll) => implode(',', array_map(fn ($c) => ['first_name' => $first, 'last_name' => 'Race', 'date_of_birth' => '2016-04-12', 'academic_year_code' => 'AY26', 'campus_code' => 'MAIN', 'grade_code' => 'G5', 'section_code' => 'A', 'roll_number' => $roll, 'start_date' => '2026-06-01'][$c] ?? '', $header));
        $rows = $import->parse(implode(',', $header)."\n".$line('One', '1')."\n".$line('Two', '2')."\n");
        $preview = $import->preview($this->school, $admin, $rows);
        $path = tempnam(sys_get_temp_dir(), 'import-race');
        file_put_contents($path, json_encode(['payload' => $rows, 'issuedAt' => $preview['issuedAt'], 'resultDigest' => StudentGuardianImport::resultDigest($preview['rows']), 'signature' => $preview['signature']], JSON_THROW_ON_ERROR));
        $command = ['php', __DIR__.'/../../Support/commit-student-import.php', $this->school->id, $admin->id, $path];

        [$holder, $contender] = $this->raceWithHeldHolder($command, $command);
        @unlink($path);

        $this->assertStringStartsWith('committed:', $holder);
        $this->assertSame($holder, $contender, 'the waiting duplicate replays the same batch');
        $ctx = app(TenantContext::class);
        $this->assertSame(2, $ctx->withSchool($this->school, fn () => DB::table('students')->where('school_id', $this->school->id)->count()));
        $this->assertSame(1, $ctx->withSchool($this->school, fn () => DB::table('student_import_batches')->where('school_id', $this->school->id)->count()));
    }
}
