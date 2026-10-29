<?php

namespace Tests\Feature\Students;

use App\Domain\Guardians\Application\StudentSiblingReader;
use App\Domain\Hostel\Application\HostelStudentSummaryReader;
use App\Domain\Library\Application\LibraryStudentSummaryReader;
use App\Domain\Transport\Application\TransportStudentSummaryReader;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use App\Support\StudentProfile\StudentProfileSections;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * SIS.EXP-1: the Student 360 profile is an aggregation surface, never an
 * owner -- it reads each domain only through that domain's approved read
 * path, writes nothing, is depended on by no domain, and its frontend holds
 * no authorization (the server omits unauthorized sections).
 */
class StudentProfileArchitectureTest extends TestCase
{
    /** The only cross-domain types the assembler may use: each owner's read path. */
    private const APPROVED_IMPORTS = [
        'App\Domain\Attendance\Application\AttendanceOperationsReadService',
        'App\Domain\Guardians\Application\StudentSiblingReader',
        'App\Domain\Hostel\Application\HostelStudentSummaryReader',
        'App\Domain\Library\Application\LibraryStudentSummaryReader',
        'App\Domain\Payments\Application\Collections\OutstandingFeeRow',
        'App\Domain\Payments\Application\Collections\OutstandingFeesReadService',
        'App\Domain\Students\Application\StudentAccess',
        'App\Domain\Students\Application\SubjectOfferingRosterReadService',
        'App\Domain\Students\Infrastructure\Student',
        'App\Domain\Students\Infrastructure\StudentEnrollment',
        'App\Domain\Transport\Application\TransportStudentSummaryReader',
    ];

    private function source(string $class): string
    {
        return (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
    }

    #[Test]
    public function the_assembler_reads_other_domains_only_through_their_approved_read_paths(): void
    {
        $source = $this->source(StudentProfileSections::class);
        preg_match_all('/^use (App\\\\Domain\\\\[^;]+);/m', $source, $imports);
        $this->assertSame(self::APPROVED_IMPORTS, $imports[1]);
        foreach (['DB::', '->save(', '->create(', '->update(', '->delete(', '->forceFill(', 'Infrastructure\\Charge', 'AttendanceRecord'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "the profile never uses {$forbidden}");
        }
    }

    #[Test]
    public function the_domain_summary_readers_are_read_only_and_bounded(): void
    {
        foreach ([TransportStudentSummaryReader::class, HostelStudentSummaryReader::class, LibraryStudentSummaryReader::class, StudentSiblingReader::class] as $class) {
            $source = $this->source($class);
            foreach (['->save(', '->create(', '->update(', '->delete(', '->insert(', 'DB::statement', 'lockForUpdate', "lock('"] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, "{$class} never writes or locks ({$forbidden})");
            }
            $this->assertMatchesRegularExpression('/->limit\(|count\(\*\)/', $source, "{$class} reads a bounded result");
        }
    }

    #[Test]
    public function no_domain_depends_on_the_profile_presentation(): void
    {
        $offenders = [];
        foreach ((new Finder)->files()->in(app_path('Domain'))->name('*.php') as $file) {
            if (str_contains($file->getContents(), 'App\Support\StudentProfile')) {
                $offenders[] = $file->getRelativePathname();
            }
        }
        $this->assertSame([], $offenders, 'Finance/Attendance/Transport/etc. never depend on SIS presentation code');
    }

    #[Test]
    public function the_profile_frontend_holds_no_capability_names(): void
    {
        $files = (new Finder)->files()->in(resource_path('js/Components/Students'))->name(['*.vue', '*.ts']);
        $files->append([resource_path('js/Pages/App/Students/Show.vue')]);
        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression(
                '/[\'"](finance|attendance|transport|hostel|library|students|enrollments|academics|guardians)\.[a-z_]+(\.[a-z_]+)*[\'"]/',
                (string) file_get_contents((string) $file),
                basename((string) $file).' must not decide access by capability name (the server omits unauthorized sections)',
            );
        }
    }

    #[Test]
    public function the_campus_adoption_of_the_keys_the_profile_reads_is_unchanged(): void
    {
        // The profile adds no Campus adoption: these keys keep their existing classification.
        foreach (['students.view', 'enrollments.view', 'attendance.view', 'finance.charges.view', 'finance.payments.view', 'transport.assignments.view', 'hostel.residency.view', 'library.circulation.view'] as $adopted) {
            $this->assertTrue(CampusScopeCatalogue::isAdopted($adopted), "{$adopted} stays Campus-adopted");
        }
        $this->assertFalse(CampusScopeCatalogue::isAdopted('academics.subjects.view'), 'subjects stay whole-School');
    }
}
