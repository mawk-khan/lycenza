<?php

namespace Tests\Feature\Postgres;

use App\Domain\Students\Application\Certificates\StudentCertificateService;
use App\Domain\Students\Application\Certificates\StudentCertificateType;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\StudentCertificate;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS.EXP-4 (rule 28): raw-SQL isolation of `student_certificates` and
 * `student_certificate_sequences` on real PostgreSQL -- no context sees
 * nothing, another School sees nothing, and a row claiming another School is
 * refused by the policy.
 */
class StudentCertificateRlsIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function setSchool(string $schoolId): void
    {
        DB::connection('pgsql')->select('select set_config(?, ?, false)', [TenantRls::SESSION_VAR, $schoolId]);
    }

    /** @return array{school: School, certificate: StudentCertificate} */
    private function issued(): array
    {
        Storage::fake((string) config('documents.disk'));
        [$admin, $school] = $this->createSchoolAdmin();
        $campus = $this->createCampus($school, ['name' => 'Main', 'code' => 'MAIN']);
        $year = $this->createAcademicYear($school, ['name' => '2026-27', 'code' => 'AY26', 'status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-04-30']);
        $section = $this->createSection($year, $campus, $this->createGradeLevel($school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]), ['name' => 'A', 'code' => 'A']);
        $student = $this->createStudent($school, ['first_name' => 'Iso', 'last_name' => 'Late', 'student_number' => 'I-1']);
        app(StudentEnrollmentService::class)->enroll($student, $section, '1', '2026-06-01', $admin);
        $placement = app(TenantContext::class)->withSchool($school, fn () => StudentEnrollment::query()->where('student_id', $student->id)->firstOrFail());
        app(StudentEnrollmentService::class)->withdraw($placement, '2026-09-15', 'left_school', $admin, $this->freshProof($admin));
        $service = app(StudentCertificateService::class);
        $fingerprint = $service->preview($school, $admin, $student)['fingerprints']['leaving'];

        return ['school' => $school, 'certificate' => $service->issue($school, $admin, $student->id, StudentCertificateType::LEAVING, $fingerprint, (string) new UuidV7, $this->freshProof($admin))];
    }

    #[Test]
    public function both_tables_have_rls_enabled_and_forced(): void
    {
        foreach (['student_certificates', 'student_certificate_sequences'] as $table) {
            $row = DB::connection('pgsql_admin')->selectOne('select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = ?::regnamespace', [$table, 'public']);
            $this->assertTrue($row->relrowsecurity, $table);
            $this->assertTrue($row->relforcerowsecurity, $table);
        }
    }

    #[Test]
    public function no_context_and_another_school_see_nothing_and_cannot_claim_a_school(): void
    {
        ['school' => $school, 'certificate' => $certificate] = $this->issued();
        app(TenantContext::class)->clearAll();

        $this->assertSame(0, (int) DB::connection('pgsql')->selectOne('select count(*) as c from student_certificates')->c);
        $this->assertSame(0, (int) DB::connection('pgsql')->selectOne('select count(*) as c from student_certificate_sequences')->c);

        $other = $this->createSchool();
        $this->setSchool($other->id);
        $this->assertCount(0, DB::connection('pgsql')->select('select id from student_certificates where id = ?', [$certificate->id]));
        $this->assertCount(0, DB::connection('pgsql')->select('select school_id from student_certificate_sequences where school_id = ?', [$school->id]));
        $this->assertThrows(fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('student_certificate_sequences')->insert([
            'school_id' => $school->id, 'certificate_type' => 'transfer', 'last_serial' => 0, 'created_at' => now(), 'updated_at' => now(),
        ])), QueryException::class);
        $this->assertSame(0, DB::connection('pgsql')->table('student_certificates')->where('id', $certificate->id)->update(['updated_at' => now()]), 'another School cannot touch the row');

        $this->setSchool($school->id);
        $this->assertCount(1, DB::connection('pgsql')->select('select id from student_certificates where id = ?', [$certificate->id]));
        // The counter only moves forward.
        $this->assertThrows(fn () => DB::connection('pgsql')->transaction(fn () => DB::connection('pgsql')->table('student_certificate_sequences')->where('school_id', $school->id)->update(['last_serial' => 0])), QueryException::class, 'only moves forward');
    }
}
