<?php

namespace Tests\Feature\Students;

use App\Domain\Documents\Infrastructure\Document;
use App\Domain\Students\Application\Certificates\StudentCertificateService;
use App\Domain\Students\Application\Certificates\StudentCertificateType;
use App\Domain\Students\Application\Documents\StudentDocumentService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentCertificate;
use App\Domain\Students\Infrastructure\StudentDocument;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * SIS.HARDEN-1 (SIS.EXP-R1 finding R-7): two genuinely separate OS processes
 * race the same terminal action against real PostgreSQL. The contender is
 * observed blocked on the record's lock, then receives the CONFLICT (409) a
 * second sequential attempt gets -- never a misleading NOT FOUND -- and
 * nothing is written or audited twice. Another School's attempt on the same
 * id stays NOT FOUND (isolation, never an existence oracle).
 * Committed fixtures (no DatabaseTransactions); removed in tearDown.
 */
class StudentTerminalActionConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    private const string PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<School> */
    private array $schools = [];

    private School $school;

    private User $admin;

    protected function tearDown(): void
    {
        $context = app(TenantContext::class);
        foreach ($this->schools as $school) {
            foreach ($context->withSchool($school, fn () => Document::query()->where('school_id', $school->id)->get()) as $document) {
                Storage::disk($document->storage_disk)->delete($document->storage_path);
            }
            $this->deleteSchoolAsAdmin($school);
        }

        parent::tearDown();
    }

    private function leaver(): Student
    {
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->schools[] = $this->school;
        $campus = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'MAIN']);
        $year = $this->createAcademicYear($this->school, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $section = $this->createSection($year, $campus, $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]), ['name' => 'A', 'code' => 'A']);
        $student = $this->createStudent($this->school, ['first_name' => 'Race', 'last_name' => 'Rao', 'student_number' => 'R-1']);
        app(StudentEnrollmentService::class)->enroll($student, $section, '1', '2026-06-01', $this->admin);
        $placement = app(TenantContext::class)->withSchool($this->school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->firstOrFail());
        app(StudentEnrollmentService::class)->withdraw($placement, '2026-09-15', 'left_school', $this->admin, $this->freshProof($this->admin));

        return app(TenantContext::class)->withSchool($this->school, fn () => Student::query()->findOrFail($student->id));
    }

    /** @return list<string> */
    private function command(string $op, School $school, Student $student, User $actor, string $recordId, int $version = 1): array
    {
        return ['php', __DIR__.'/../../Support/terminal-student-record-op.php', $op, $school->id, $student->id, $actor->id, $recordId, (string) $version];
    }

    private function audits(string $type): int
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => SchoolAuditEvent::query()->where('event_type', $type)->count());
    }

    #[Test]
    public function the_loser_of_two_concurrent_certificate_voids_gets_a_conflict_not_a_not_found(): void
    {
        $student = $this->leaver();
        $service = app(StudentCertificateService::class);
        $fingerprint = $service->preview($this->school, $this->admin, $student)['fingerprints']['leaving'];
        $certificate = $service->issue($this->school, $this->admin, $student->id, StudentCertificateType::LEAVING, $fingerprint, (string) new UuidV7, $this->freshProof($this->admin));

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->command('void', $this->school, $student, $this->admin, $certificate->id),
            $this->command('void', $this->school, $student, $this->admin, $certificate->id),
        );

        $this->assertSame('done', $holder);
        $this->assertSame('rejected:App\\Domain\\Students\\Application\\Exceptions\\StudentCertificateNotIssuedException:409', $contender);
        $this->assertFalse(app(TenantContext::class)->withSchool($this->school, fn () => StudentCertificate::query()->findOrFail($certificate->id))->isIssued());
        $this->assertSame(1, $this->audits('student_certificate.voided'), 'the loser audits nothing');
    }

    #[Test]
    public function the_loser_of_two_concurrent_document_archives_gets_a_conflict_not_a_not_found(): void
    {
        $student = $this->leaver();
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, self::PDF);
        $record = app(StudentDocumentService::class)->upload($this->school, $this->admin, $student->id, new UploadedFile($path, 'record.pdf', null, null, true),
            ['category' => 'enrollment_support', 'title' => 'Record'], (string) new UuidV7, $this->freshProof($this->admin));

        $version = $this->versionOf($record);
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->command('archive', $this->school, $student, $this->admin, $record->id, $version),
            $this->command('archive', $this->school, $student, $this->admin, $record->id, $version),
        );

        $this->assertSame('done', $holder);
        $this->assertSame('rejected:App\\Domain\\Students\\Application\\Exceptions\\StudentDocumentArchivedException:409', $contender);
        $fresh = app(TenantContext::class)->withSchool($this->school, fn () => StudentDocument::query()->findOrFail($record->id));
        $this->assertNotNull($fresh->archived_at);
        $this->assertSame($version + 1, $fresh->version, 'archived exactly once');
        $this->assertSame(1, $this->audits('student_document.archived'));
        $this->assertSame(1, $this->audits('document.archived'));
    }

    #[Test]
    public function another_school_still_gets_not_found_for_the_same_certificate_and_document(): void
    {
        $student = $this->leaver();
        [$schoolA, $adminA] = [$this->school, $this->admin];
        $service = app(StudentCertificateService::class);
        $certificate = $service->issue($schoolA, $adminA, $student->id, StudentCertificateType::LEAVING,
            $service->preview($schoolA, $adminA, $student)['fingerprints']['leaving'], (string) new UuidV7, $this->freshProof($adminA));
        $path = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, self::PDF);
        $record = app(StudentDocumentService::class)->upload($schoolA, $adminA, $student->id, new UploadedFile($path, 'record.pdf', null, null, true),
            ['category' => 'enrollment_support', 'title' => 'Record'], (string) new UuidV7, $this->freshProof($adminA));
        $version = $this->versionOf($record);

        // A whole-School administrator of ANOTHER School naming A's ids.
        $otherStudent = $this->leaver();
        $void = $this->runOp($this->command('void', $this->school, $otherStudent, $this->admin, $certificate->id));
        $archive = $this->runOp($this->command('archive', $this->school, $otherStudent, $this->admin, $record->id, $version));
        $foreignStudent = $this->runOp($this->command('void', $this->school, $student, $this->admin, $certificate->id));

        $this->assertStringStartsWith('rejected:Illuminate\\Database\\Eloquent\\ModelNotFoundException', $void);
        $this->assertStringStartsWith('rejected:Illuminate\\Database\\Eloquent\\ModelNotFoundException', $archive);
        $this->assertStringStartsWith('rejected:', $foreignStudent);
        $this->assertStringNotContainsString(':409', $foreignStudent, 'another School never learns the state of A\'s records');
        $this->assertTrue(app(TenantContext::class)->withSchool($schoolA, fn () => StudentCertificate::query()->findOrFail($certificate->id))->isIssued());
        $this->assertNull(app(TenantContext::class)->withSchool($schoolA, fn () => StudentDocument::query()->findOrFail($record->id))->archived_at);
    }

    private function versionOf(StudentDocument $record): int
    {
        return (int) app(TenantContext::class)->withSchool($this->school, fn () => StudentDocument::query()->findOrFail($record->id))->version;
    }

    /** @param  list<string>  $command */
    private function runOp(array $command): string
    {
        $process = new Process($command, null, ['CONCURRENCY_SESSION_NAME' => 'solo_'.bin2hex(random_bytes(4))]);
        $process->setTimeout(120);
        $process->run();

        return trim($process->getOutput());
    }
}
