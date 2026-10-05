<?php

namespace Tests\Feature\Retention;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * E21.3B (E21-D7, E21.2G O1/P1/I2): the new purges racing the changes
 * that could matter, in real separate transactions.
 * - Operational module expiry vs re-enrollment (two OS processes): the
 *   purge locks the Student row FOR UPDATE and rechecks the exit after the
 *   lock; an Enrollment insert takes FOR KEY SHARE on the Student. A
 *   re-entry that committed first keeps the history.
 * - Core expiry vs a new processing authorization (two OS processes): the
 *   authorization insert takes FOR KEY SHARE on the Student too. Recorded
 *   first, it goes with the record it explains (it never extends the core
 *   period); purged first, the late insert fails on its foreign key. Never
 *   a half-deleted record.
 * - Invitation expiry vs a revocation holding the row (two connections):
 *   the purge skips a locked row and re-applies its predicate, so a row
 *   being changed is never deleted under the change.
 * A returned loan is never reopened and an ended assignment never
 * reactivated (LibraryLoanService, TransportStudentAssignmentService,
 * HostelResidencyService), so no race on those transitions exists; new
 * loans and assignments are only ever active, which the purge never
 * deletes.
 */
class StudentLinkedRetentionConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    /** @var list<string> */
    private array $schoolIds = [];

    protected function tearDown(): void
    {
        DB::purge('pgsql_race');
        foreach ($this->schoolIds as $schoolId) {
            $this->deleteSchoolAsAdmin($schoolId);
        }

        parent::tearDown();
    }

    private function script(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/student-retention-op.php', ...$args];
    }

    /** @return array{0: School, 1: Student, 2: string} school, a leaver, a next-year Section id */
    private function leaver(string $endsOn): array
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $year = $this->createAcademicYear($school, ['starts_on' => substr($endsOn, 0, 4).'-01-01', 'ends_on' => substr($endsOn, 0, 4).'-12-31', 'status' => 'active', 'code' => 'AYX']);
        $next = $this->createAcademicYear($school, ['starts_on' => '2027-04-01', 'ends_on' => '2028-03-31', 'status' => 'draft', 'code' => 'AY27']);
        $student = $this->createStudent($school, ['status' => 'inactive']);
        $this->createStudentEnrollment($student, $this->createSection($year, $campus, $grade, ['code' => 'A']), ['status' => 'withdrawn', 'starts_on' => substr($endsOn, 0, 4).'-02-01', 'ends_on' => $endsOn]);

        // E21-RH.6: the end was also RECORDED back then (the database counts from the later of the two).
        if (method_exists($this, 'backdateEndRecording')) {
            $this->backdateEndRecording();
        }

        return [$school, $student, $this->createSection($next, $campus, $grade, ['code' => 'B'])->id];
    }

    private function admin(string $table, string $column, string $id): int
    {
        return DB::connection('pgsql_admin')->table($table)->where($column, $id)->count();
    }

    #[Test]
    public function a_re_enrollment_committed_first_keeps_library_history(): void
    {
        [$school, $student, $sectionId] = $this->leaver('2026-09-30');
        $this->createLibraryLoan($this->createLibraryCopy($this->createLibraryTitle($school)), $student, ['status' => 'returned', 'checked_out_at' => '2026-07-01 09:00:00', 'due_at' => '2026-07-15 09:00:00', 'checked_in_at' => '2026-07-10 09:00:00']);

        $this->backdateEndRecording();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('re-enroll', $school->id, $student->id, $sectionId),
            $this->script('operational-prune', $school->id, '2060-01-01'),
        );

        $this->assertSame('re-enrolled', $holder);
        $this->assertSame('deleted:0', $contender);
        $this->assertSame(1, $this->admin('library_loans', 'student_id', $student->id));
    }

    #[Test]
    public function an_authorization_recorded_first_keeps_the_record_it_explains_as_recorded_within_the_period(): void
    {
        [$school, $student] = $this->leaver('2000-09-30');

        $this->backdateEndRecording();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('record-authorization', $school->id, $student->id, $this->createUser()->id),
            $this->script('core-prune', $school->id, '2001-01-01'),
        );

        // E21-RH.7: the authorization was recorded by the database just now, so the core unit keeps the Student with
        // it (a retained dependent, never an error) until that recording, too, is past the period.
        $this->assertSame('recorded', $holder);
        $this->assertSame('deleted:0', $contender);
        $this->assertSame(1, $this->admin('students', 'id', $student->id));
        $this->assertSame(1, $this->admin('student_processing_authorizations', 'student_id', $student->id));
    }

    #[Test]
    public function a_core_purge_committed_first_refuses_the_late_authorization(): void
    {
        [$school, $student] = $this->leaver('2000-09-30');

        $this->backdateEndRecording();
        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->script('core-prune', $school->id, '2001-01-01'),
            $this->script('record-authorization', $school->id, $student->id, $this->createUser()->id),
        );

        $this->assertSame('deleted:1', $holder);
        $this->assertStringStartsWith('rejected:', $contender);
        $this->assertSame(0, $this->admin('students', 'id', $student->id));
        $this->assertSame(0, $this->admin('student_processing_authorizations', 'student_id', $student->id));
    }

    #[Test]
    public function an_invitation_being_revoked_is_skipped_and_its_new_end_restarts_the_clock(): void
    {
        $this->travelTo(Carbon::parse('2026-05-20 12:00:00', 'UTC'));
        config(['retention.portal_invitation_days' => 7]);
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;
        $id = (string) Str::uuid7();
        app(TenantContext::class)->withSchool($school, fn () => DB::table('identity_account_invitations')->insert([
            'id' => $id, 'school_id' => $school->id, 'guardian_id' => $this->createGuardian($school)->id, 'token_hash' => hash('sha256', Str::random(40)),
            'destination_email_hash' => hash('sha256', Str::random(12)), 'status' => 'pending', 'expires_at' => '2026-04-01 00:00:00',
            'invited_by_user_id' => $this->createUser()->id, 'created_at' => now(), 'updated_at' => now(),
        ]));

        // A second, independent runtime connection holds the row, as a resend's revocation does.
        Config::set('database.connections.pgsql_race', config('database.connections.pgsql'));
        $race = DB::connection('pgsql_race');
        $race->beginTransaction();
        $race->select("select set_config('app.current_school_id', ?, true)", [$school->id]);
        $this->assertCount(1, $race->select('select id from identity_account_invitations where id = ? for update', [$id]));

        $this->artisan('platform:portal-invitations-prune')->expectsOutputToContain('Deleted 0 ended portal invitation(s)')->assertSuccessful();
        $this->assertSame(1, $this->admin('identity_account_invitations', 'id', $id), 'a locked row is skipped, never deleted under the change');

        $race->update("update identity_account_invitations set status = 'revoked', revoked_at = ?, updated_at = ? where id = ?", ['2026-05-20 12:00:00', '2026-05-20 12:00:00', $id]);
        $race->commit();

        $this->artisan('platform:portal-invitations-prune')->expectsOutputToContain('Deleted 0 ended portal invitation(s)')->assertSuccessful();
        $this->assertSame('revoked', DB::connection('pgsql_admin')->table('identity_account_invitations')->where('id', $id)->value('status'));

        $this->travelTo(Carbon::parse('2026-05-28 12:00:00', 'UTC'));
        $this->artisan('platform:portal-invitations-prune')->expectsOutputToContain('Deleted 1 ended portal invitation(s)')->assertSuccessful();
        $this->assertSame(0, $this->admin('identity_account_invitations', 'id', $id));
    }
}
