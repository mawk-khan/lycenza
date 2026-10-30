<?php

namespace Tests\Feature\Students;

use App\Domain\Documents\Infrastructure\Document;
use App\Domain\Students\Application\Certificates\StudentCertificateService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentCertificate;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * SIS.EXP-4: REQUIRED real-concurrency proof -- two genuinely separate OS
 * processes issue certificates at the same time against real PostgreSQL.
 * - Two Students: the contender is observed blocked on the School + type
 *   counter row, then receives the NEXT number (never the same one).
 * - One Student, two forms: the contender is blocked on the Student lock,
 *   then refused (one issued certificate per exit).
 * Committed fixtures (no DatabaseTransactions); removed in tearDown.
 */
class StudentCertificateConcurrencyTest extends TestCase
{
    use CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    private User $admin;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $context = app(TenantContext::class);
            foreach ($context->withSchool($this->school, fn () => Document::query()->where('school_id', $this->school->id)->get()) as $document) {
                Storage::disk($document->storage_disk)->delete($document->storage_path);
            }
            $this->deleteSchoolAsAdmin($this->school);
        }

        parent::tearDown();
    }

    /** @return list<Student> */
    private function leavers(int $n): array
    {
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'MAIN']);
        $year = $this->createAcademicYear($this->school, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $section = $this->createSection($year, $campus, $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]), ['name' => 'A', 'code' => 'A']);
        $students = [];
        for ($i = 1; $i <= $n; $i++) {
            $s = $this->createStudent($this->school, ['first_name' => "Race{$i}", 'last_name' => 'Rao', 'student_number' => "R-{$i}"]);
            app(StudentEnrollmentService::class)->enroll($s, $section, (string) $i, '2026-06-01', $this->admin);
            $placement = app(TenantContext::class)->withSchool($this->school, fn () => StudentEnrollment::query()->where('student_id', $s->id)->firstOrFail());
            app(StudentEnrollmentService::class)->withdraw($placement, '2026-09-15', 'left_school', $this->admin, $this->freshProof($this->admin));
            $students[] = app(TenantContext::class)->withSchool($this->school, fn () => Student::query()->findOrFail($s->id));
        }

        return $students;
    }

    /** @return list<string> */
    private function command(Student $s, ?string $formKey = null): array
    {
        $fingerprint = app(StudentCertificateService::class)->preview($this->school, $this->admin, $s)['fingerprints']['leaving'];

        return ['php', __DIR__.'/../../Support/issue-student-certificate.php', $this->school->id, $s->id, $this->admin->id, 'leaving', $fingerprint, $formKey ?? (string) new UuidV7];
    }

    #[Test]
    public function two_concurrent_issuances_for_two_students_get_consecutive_distinct_numbers(): void
    {
        [$a, $b] = $this->leavers(2);
        [$holder, $contender] = $this->raceWithHeldHolder($this->command($a), $this->command($b));

        $this->assertSame('issued:LC-000001', $holder);
        $this->assertSame('issued:LC-000002', $contender, 'the contender waited on the counter and took the next number');
        $serials = app(TenantContext::class)->withSchool($this->school, fn () => StudentCertificate::query()->orderBy('serial')->pluck('serial')->all());
        $this->assertSame([1, 2], $serials);
    }

    #[Test]
    public function two_concurrent_issuances_for_the_same_exit_issue_exactly_one(): void
    {
        [$a] = $this->leavers(1);
        [$holder, $contender] = $this->raceWithHeldHolder($this->command($a), $this->command($a));

        $this->assertSame('issued:LC-000001', $holder);
        $this->assertSame('rejected:App\\Domain\\Students\\Application\\Exceptions\\StudentCertificateAlreadyIssuedException', $contender);
        $this->assertSame(1, app(TenantContext::class)->withSchool($this->school, fn () => StudentCertificate::query()->count()));
    }

    #[Test]
    public function a_concurrent_double_submit_of_the_same_form_replays_the_first_certificate(): void
    {
        [$a] = $this->leavers(1);
        $key = (string) new UuidV7;
        [$holder, $contender] = $this->raceWithHeldHolder($this->command($a, $key), $this->command($a, $key));

        $this->assertSame('issued:LC-000001', $holder);
        $this->assertSame('issued:LC-000001', $contender, 'the waiting duplicate replays, never refuses or issues twice');
        $this->assertSame(1, app(TenantContext::class)->withSchool($this->school, fn () => StudentCertificate::query()->count()));
    }
}
