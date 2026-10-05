<?php

namespace Tests\Feature\Retention;

use App\Domain\Guardians\Infrastructure\Guardian;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * E21.3C (E21.2G G1/AD2): the Guardian and Admissions purges racing the
 * writes that matter, in two real OS processes with an observed lock wait.
 * - Guardian purge vs a new Student relationship: the purge locks the
 *   Guardian row FOR UPDATE and rechecks; a relationship insert takes FOR
 *   KEY SHARE on the Guardian (and its marker trigger locks it FOR UPDATE).
 *   A link committed first keeps the Guardian (clock stopped); a purge
 *   committed first makes the late link fail on its foreign key.
 * - Guardian purge vs a new account link: same lock; a link committed
 *   first keeps the Guardian (an active link blocks).
 * - Admissions purge vs a new application for the same applicant: the purge
 *   locks the applicant FOR UPDATE; the insert takes FOR KEY SHARE. Either
 *   way the expired application goes and the applicant stays with the new
 *   one (or the late insert fails safely after the applicant left).
 * A rejected/withdrawn application can never be converted or reopened
 * (AdmissionApplicationService/AdmissionConversionService refuse any
 * transition out of them), so no conversion race exists for an eligible
 * row; the conversion path locks only accepted applications.
 */
class AdmissionsGuardianRetentionConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    protected function tearDown(): void
    {
        foreach ($this->schoolIds as $schoolId) {
            $this->deleteSchoolAsAdmin($schoolId);
        }

        parent::tearDown();
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/guardian-retention-op.php', ...$args];
    }

    private function school(): School
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;

        return $school;
    }

    private function unlinkedGuardian(School $school): Guardian
    {
        return app(TenantContext::class)->withSchool($school, fn () => Guardian::factory()->create(['school_id' => $school->id, 'no_relationship_since' => '2020-01-01 00:00:00']));
    }

    private function admin(string $table, string $column, string $id): int
    {
        return DB::connection('pgsql_admin')->table($table)->where($column, $id)->count();
    }

    #[Test]
    public function a_relationship_committed_first_keeps_the_guardian_and_stops_its_clock(): void
    {
        $school = $this->school();
        $guardian = $this->unlinkedGuardian($school);
        $student = $this->createStudent($school);

        $this->backdateEndRecording();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('link', $school->id, $guardian->id, $student->id),
            $this->script('guardian-prune', $school->id, '2024-01-01 00:00:00'),
        );

        $this->assertSame('linked', $holder);
        $this->assertSame('deleted:0', $contender);
        $this->assertSame(1, $this->admin('guardians', 'id', $guardian->id));
        $this->assertNull(DB::connection('pgsql_admin')->table('guardians')->where('id', $guardian->id)->value('no_relationship_since'));
    }

    #[Test]
    public function a_guardian_purge_committed_first_refuses_the_late_relationship(): void
    {
        $school = $this->school();
        $guardian = $this->unlinkedGuardian($school);
        $student = $this->createStudent($school);

        $this->backdateEndRecording();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('guardian-prune', $school->id, '2024-01-01 00:00:00'),
            $this->script('link', $school->id, $guardian->id, $student->id),
        );

        $this->assertSame('deleted:1', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(0, $this->admin('guardians', 'id', $guardian->id));
        $this->assertSame(0, $this->admin('student_guardian_relationships', 'guardian_id', $guardian->id));
    }

    #[Test]
    public function an_account_link_committed_first_keeps_the_guardian(): void
    {
        $school = $this->school();
        $guardian = $this->unlinkedGuardian($school);
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);

        $this->backdateEndRecording();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('account-link', $school->id, $guardian->id, $membership->id, $user->id),
            $this->script('guardian-prune', $school->id, '2024-01-01 00:00:00'),
        );

        $this->assertSame('account-linked', $holder);
        $this->assertSame('deleted:0', $contender);
        $this->assertSame(1, $this->admin('guardians', 'id', $guardian->id));
    }

    #[Test]
    public function a_new_application_keeps_its_applicant_whichever_commits_first(): void
    {
        $school = $this->school();
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $year = $this->createAcademicYear($school);
        $applicant = $this->createApplicant($school);
        $rejected = (string) Str::uuid7();
        app(TenantContext::class)->withSchool($school, fn () => DB::table('admission_applications')->insert([
            'id' => $rejected, 'school_id' => $school->id, 'applicant_id' => $applicant->id, 'academic_year_id' => $year->id, 'campus_id' => $campus->id,
            'grade_level_id' => $grade->id, 'status' => 'rejected', 'terminal_at' => '2020-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->backdateEndRecording();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('apply', $school->id, $applicant->id, $year->id, $campus->id, $grade->id),
            $this->script('admissions-prune', $school->id, '2024-01-01 00:00:00'),
        );

        $this->assertSame('applied', $holder);
        $this->assertSame('deleted:1', $contender);
        $this->assertSame(0, $this->admin('admission_applications', 'id', $rejected));
        $this->assertSame(1, $this->admin('applicants', 'id', $applicant->id), 'the applicant stays with its new application');
        $this->assertSame(1, $this->admin('admission_applications', 'applicant_id', $applicant->id));
    }

    #[Test]
    public function an_admissions_purge_committed_first_refuses_the_late_application(): void
    {
        $school = $this->school();
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $year = $this->createAcademicYear($school);
        $applicant = $this->createApplicant($school);
        app(TenantContext::class)->withSchool($school, fn () => DB::table('admission_applications')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'applicant_id' => $applicant->id, 'academic_year_id' => $year->id, 'campus_id' => $campus->id,
            'grade_level_id' => $grade->id, 'status' => 'withdrawn', 'terminal_at' => '2020-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->backdateEndRecording();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('admissions-prune', $school->id, '2024-01-01 00:00:00'),
            $this->script('apply', $school->id, $applicant->id, $year->id, $campus->id, $grade->id),
        );

        $this->assertSame('deleted:1', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(0, $this->admin('applicants', 'id', $applicant->id));
    }
}
