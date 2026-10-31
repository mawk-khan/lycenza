<?php

namespace Tests\Feature\Students;

use App\Domain\Guardians\Application\GuardianProfileVersion;
use App\Domain\Guardians\Application\GuardianService;
use App\Domain\Guardians\Application\StudentSiblingReader;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Students\Application\Movements\StudentMovementRegister;
use App\Domain\Students\Application\StudentProfileVersion;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Support\StudentProfile\StudentProfileSections;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SIS.HARDEN-1: the residual findings closed here (SIS.EXP-R1 R-1, R-3, R-4,
 * R-6) must not silently return. The role catalogue, Campus adoptions and
 * capability catalogue stay pinned by StaffRoleCatalogueSnapshotTest,
 * CampusScopeAdoptionArchitectureTest and StaffRoleGrantAuthorityTest; this
 * guard repeats only the count, as this checkpoint's "no new capability".
 */
class SisResidualHardeningArchitectureTest extends TestCase
{
    private function body(string $class, string $method): string
    {
        $m = new ReflectionMethod($class, $method);
        $lines = file((string) $m->getFileName());

        return implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    }

    #[Test]
    public function guardian_structural_fields_stay_out_of_mass_assignment_and_the_edit_is_whitelisted(): void
    {
        $this->assertSame(['first_name', 'middle_name', 'last_name'], (new Guardian)->getFillable());
        $this->assertSame(['first_name', 'middle_name', 'last_name'], GuardianService::PROFILE_FIELDS);
        $update = $this->body(GuardianService::class, 'update');
        foreach (["lock('for no key update')", 'holdsInSchoolNow', 'GuardianProfileVersion::matches', 'getDirty()'] as $needle) {
            $this->assertStringContainsString($needle, $update, $needle);
        }
    }

    #[Test]
    public function the_guardian_stale_token_stays_keyed_and_separate_from_the_student_key(): void
    {
        $source = (string) file_get_contents((string) (new ReflectionClass(GuardianProfileVersion::class))->getFileName());
        $this->assertStringContainsString('hash_hmac(', $source);
        $this->assertStringContainsString("config('app.key')", $source);
        $this->assertStringContainsString('lycenza:guardian-profile-version:v1', $source);
        $this->assertStringContainsString('hash_equals(', $source);
        $this->assertStringNotContainsString('lycenza:guardian-profile-version', (string) file_get_contents((string) (new ReflectionClass(StudentProfileVersion::class))->getFileName()));
    }

    #[Test]
    public function enrollment_ownership_and_lifecycle_stay_out_of_mass_assignment(): void
    {
        $this->assertSame(['school_id', 'status', 'ends_on'], StudentEnrollment::PROTECTED_FIELDS);
        $this->assertSame([], array_values(array_intersect(StudentEnrollment::PROTECTED_FIELDS, (new StudentEnrollment)->getFillable())));
        $this->assertNotContains('*', (new StudentEnrollment)->getFillable());
    }

    #[Test]
    public function outside_year_events_are_never_assigned_to_a_year(): void
    {
        $this->assertSame('outside', StudentMovementRegister::OUTSIDE_ACADEMIC_YEAR);
        $query = $this->body(StudentMovementRegister::class, 'query');
        $this->assertStringContainsString("whereNull('r.academic_year_id')->whereNotExists(", $query, 'outside = no year link AND no containing year');
        $this->assertDoesNotMatchRegularExpression('/nearest|closest|abs\(|least\(|greatest\(/i', $query, 'never a nearest-year rule');
    }

    #[Test]
    public function the_sibling_limit_is_applied_only_after_the_viewers_scope(): void
    {
        $this->assertStringNotContainsString('limit(', $this->body(StudentSiblingReader::class, 'siblingIds'), 'the candidate set has no limit of its own');
        $family = $this->body(StudentProfileSections::class, 'family');
        $scoped = strpos($family, '$this->students->students($school, $scope)->whereIn(\'students.id\', $this->siblings->siblingIds($student))');
        $limited = strpos($family, '->limit(StudentSiblingReader::LIMIT + 1)');
        $this->assertNotFalse($scoped);
        $this->assertNotFalse($limited);
        $this->assertGreaterThan($scoped, $limited, 'scope first, then the limit, in the same query');
        $this->assertStringNotContainsString('count($shared)', $family, 'nothing counts the unauthorized candidate set');
    }

    #[Test]
    public function no_capability_was_added(): void
    {
        // SIS.HARDEN-1 added none; SIS 11.4 (D-11) added students.export and admissions.export.
        $this->assertSame(188, DB::table('capabilities')->where('namespace', 'school')->count(), 'only reviewed releases add School capabilities');
        $this->assertSame(213, DB::table('capabilities')->count(), 'and none in any other namespace');
    }
}
