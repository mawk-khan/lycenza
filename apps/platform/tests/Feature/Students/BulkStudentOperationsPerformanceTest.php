<?php

namespace Tests\Feature\Students;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\Bulk\BulkPlacementOperation;
use App\Domain\Students\Application\Bulk\BulkPlacementRequest;
use App\Domain\Students\Application\Bulk\BulkStudentPlacementService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Authorization\Campus\CampusScope;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 11.13 (docs/sis/SIS-11-13-BULK-STUDENT-OPERATIONS.md §9): the bulk
 * move's cost is the authoritative single-Student primitive per Student --
 * deliberately (no relaxed batch rules) -- plus a CONSTANT set of selection,
 * label, scope and capacity reads. Pinned: the per-Student slope is bounded
 * and the fixed part does not grow with the batch; the maximum batch
 * (BulkStudentPlacementService::MAX_STUDENTS) previews and executes in one
 * synchronous request.
 */
class BulkStudentOperationsPerformanceTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $admin;

    private Section $from;

    private Section $to;

    /** @var list<array{studentId: string, enrollmentId: string, rollNumber: string}> */
    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($this->school);
        $grade = $this->createGradeLevel($this->school);
        $year = $this->createAcademicYear($this->school, ['status' => 'active', 'starts_on' => now()->subDays(200)->toDateString(), 'ends_on' => now()->addDays(100)->toDateString()]);
        $this->from = $this->createSection($year, $campus, $grade, ['code' => 'F', 'name' => 'F', 'status' => 'active']);
        $this->to = $this->createSection($year, $campus, $grade, ['code' => 'T', 'name' => 'T', 'status' => 'active', 'capacity' => 40]);
        for ($i = 1; $i <= BulkStudentPlacementService::MAX_STUDENTS; $i++) {
            $student = $this->createStudent($this->school, ['status' => 'active']);
            $placement = $this->createStudentEnrollment($student, $this->from, ['status' => 'active', 'roll_number' => (string) $i, 'starts_on' => now()->subDays(100)->toDateString()]);
            $this->rows[] = ['studentId' => $student->id, 'enrollmentId' => $placement->id, 'rollNumber' => "N{$i}"];
        }
    }

    private function request(int $n): BulkPlacementRequest
    {
        return new BulkPlacementRequest(BulkPlacementOperation::SectionChange, $this->from->id, $this->to->id, SchoolTimezone::today($this->school), array_slice($this->rows, 0, $n));
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function previewQueries(int $n): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $preview = app(BulkStudentPlacementService::class)->preview($this->school, $this->admin, CampusScope::wholeSchool(), $this->request($n));
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$count, $preview];
    }

    #[Test]
    public function the_preview_costs_the_primitive_per_student_plus_a_constant(): void
    {
        [$ten] = $this->previewQueries(10);
        [$twenty] = $this->previewQueries(20);
        [$forty] = $this->previewQueries(40);
        $slope = ($twenty - $ten) / 10;

        // Measured 18 (SIS 11.13 §9): the single-Student transfer's own statements (locks,
        // checks, the source end, the new row, two audit rows) plus its tenant-context
        // set/restore and the reload of the placement the operator saw.
        $this->assertLessThanOrEqual(20, $slope, "per-Student queries: {$slope}");
        $this->assertEquals($twenty - $ten, ($forty - $twenty) / 2, 'linear: no hidden per-Student label or relationship reads');
        $this->assertLessThanOrEqual(30, $ten - 10 * $slope, 'the fixed part (selection, labels, scope, capacity) stays small');
    }

    #[Test]
    public function the_maximum_batch_previews_and_executes_synchronously(): void
    {
        $start = microtime(true);
        $preview = app(BulkStudentPlacementService::class)->preview($this->school, $this->admin, CampusScope::wholeSchool(), $this->request(BulkStudentPlacementService::MAX_STUDENTS));
        $previewSeconds = microtime(true) - $start;
        $this->assertTrue($preview['executable']);
        $this->assertNotNull($preview['capacity']['warning'], '100 into a class of 40: advisory only');

        $start = microtime(true);
        $result = app(BulkStudentPlacementService::class)->execute($this->school, $this->admin, $this->request(BulkStudentPlacementService::MAX_STUDENTS), $preview['issuedAt'], $preview['signature'], new FreshMfaProof($this->admin->id, CarbonImmutable::now()));
        $executeSeconds = microtime(true) - $start;

        $this->assertCount(BulkStudentPlacementService::MAX_STUDENTS, $result['moved']);
        $this->assertSame(BulkStudentPlacementService::MAX_STUDENTS, app(TenantContext::class)->withSchool($this->school, fn () => StudentEnrollment::query()->where('section_id', $this->to->id)->where('status', 'active')->count()));
        // Generous ceilings for a shared CI runner; the measured figures are in the SIS 11.13 record.
        $this->assertLessThan(20, $previewSeconds, sprintf('preview %.2fs', $previewSeconds));
        $this->assertLessThan(20, $executeSeconds, sprintf('execute %.2fs', $executeSeconds));
        fwrite(STDERR, sprintf("\n[SIS 11.13] %d Students: preview %.2fs, execute %.2fs\n", BulkStudentPlacementService::MAX_STUDENTS, $previewSeconds, $executeSeconds));
    }
}
