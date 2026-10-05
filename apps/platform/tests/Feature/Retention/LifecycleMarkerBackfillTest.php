<?php

namespace Tests\Feature\Retention;

use App\Domain\Admissions\Application\Retention\AdmissionDecisionBackfill;
use App\Domain\Admissions\Application\Retention\TerminalApplicationRetentionService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Guardians\Application\Retention\GuardianMarkerBackfill;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Models\School;
use App\Support\Retention\RetentionPeriod;
use App\Support\Retention\TenantClosureReadiness;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.3C: rows that predate the lifecycle markers are backfilled ONLY from
 * trustworthy audit evidence; everything else stays NULL (`unresolved`)
 * and is kept. Legacy rows are written COMMITTED through the admin
 * connection with triggers off (`session_replication_role = replica`),
 * exactly as they exist from before the migration.
 */
class LifecycleMarkerBackfillTest extends TestCase
{
    use CreatesTenancyFixtures;

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

    private function school(): School
    {
        $school = $this->createSchool();
        $this->schoolIds[] = $school->id;

        return $school;
    }

    /** @param  callable(Connection): void  $write */
    private function legacy(callable $write): void
    {
        $admin = DB::connection('pgsql_admin');
        $admin->statement('SET session_replication_role = replica');
        try {
            $write($admin);
        } finally {
            $admin->statement('SET session_replication_role = origin');
        }
    }

    private function audit(string $schoolId, string $eventType, string $occurredAt, ?string $subjectType, ?string $subjectId, array $metadata = []): void
    {
        $this->legacy(fn ($admin) => $admin->table('school_audit_events')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $schoolId, 'occurred_at' => $occurredAt, 'event_type' => $eventType,
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'metadata' => json_encode($metadata), 'created_at' => $occurredAt,
            // E21-RH.7: legacy evidence, recorded by the database back then (the owner's maintenance path may say so).
            'retention_recorded_at' => $occurredAt,
        ]));
    }

    /** A pre-migration terminal application (terminal_at NULL). */
    private function legacyApplication(School $school, string $status): string
    {
        $applicant = $this->createApplicant($school);
        $year = $this->createAcademicYear($school, ['code' => 'Y'.Str::random(5)]);
        $id = (string) Str::uuid7();
        $this->legacy(fn ($admin) => $admin->table('admission_applications')->insert([
            'id' => $id, 'school_id' => $school->id, 'applicant_id' => $applicant->id, 'academic_year_id' => $year->id,
            'campus_id' => $this->createCampus($school)->id, 'grade_level_id' => $this->createGradeLevel($school)->id,
            'status' => $status, 'terminal_at' => null, 'created_at' => '2019-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
            'retention_recorded_at' => '2019-01-01 00:00:00', // E21-RH.7: legacy, recorded back then
        ]));
        $this->legacy(fn ($admin) => $admin->table('applicants')->where('id', $applicant->id)->update(['retention_recorded_at' => '2019-01-01 00:00:00']));

        return $id;
    }

    private function legacyGuardian(School $school): Guardian
    {
        return app(TenantContext::class)->withSchool($school, fn () => Guardian::factory()->create(['school_id' => $school->id, 'no_relationship_since' => null]));
    }

    private function terminalAt(string $id): ?string
    {
        return DB::connection('pgsql_admin')->table('admission_applications')->where('id', $id)->value('terminal_at');
    }

    private function marker(string $id): ?string
    {
        return DB::connection('pgsql_admin')->table('guardians')->where('id', $id)->value('no_relationship_since');
    }

    #[Test]
    public function admissions_decisions_are_mapped_only_from_their_own_single_audit_event(): void
    {
        $school = $this->school();
        $other = $this->school();
        $mapped = $this->legacyApplication($school, 'rejected');
        $this->audit($school->id, 'admission_application.rejected', '2021-04-05 09:30:00', AdmissionApplication::class, $mapped);
        $noEvidence = $this->legacyApplication($school, 'withdrawn');
        $mismatch = $this->legacyApplication($school, 'rejected');
        $this->audit($school->id, 'admission_application.withdrawn', '2021-04-05 09:30:00', AdmissionApplication::class, $mismatch);
        $foreign = $this->legacyApplication($school, 'rejected');
        $this->audit($other->id, 'admission_application.rejected', '2021-04-05 09:30:00', AdmissionApplication::class, $foreign);

        // Exact per-School counts through the service (the command walks every School in the database).
        $backfill = app(AdmissionDecisionBackfill::class);
        $this->assertSame(['mapped' => 1, 'already_mapped' => 0, 'unresolved' => 3, 'error' => 0], $backfill->run($school, 500, true));
        $this->artisan('platform:lifecycle-markers-backfill', ['--only' => 'admissions', '--dry-run' => true])->expectsOutputToContain('Dry run: admissions: mapped ')->assertSuccessful();
        $this->assertNull($this->terminalAt($mapped), 'a dry run writes nothing');

        // Readiness: the undated terminal rows are unresolved, never purge-ready.
        $report = app(TenantClosureReadiness::class)->report($school);
        $this->assertContains('retention_trigger_unresolved', $report['gates']);

        $this->artisan('platform:lifecycle-markers-backfill', ['--only' => 'admissions'])->expectsOutputToContain('admissions: mapped ')->assertSuccessful();
        $this->assertSame('2021-04-05 09:30:00', $this->terminalAt($mapped));
        foreach ([$noEvidence, $mismatch, $foreign] as $id) {
            $this->assertNull($this->terminalAt($id), 'no trustworthy evidence: unresolved and kept');
        }

        // Rerun: same input, same result.
        $this->assertSame(['mapped' => 0, 'already_mapped' => 1, 'unresolved' => 3, 'error' => 0], $backfill->run($school, 500, false));

        // The mapped one now expires on its decision; the unresolved ones never do.
        config(['retention.admissions_terminal_years' => 1, 'retention.hold_school_ids' => []]);
        $this->assertSame(['eligible' => 1, 'deleted' => 1, 'unresolved' => 3, 'dependency_blocked' => 0, 'errors' => 0, 'held' => 0],
            app(TerminalApplicationRetentionService::class)->prune($school, RetentionPeriod::yearsBeforeNow(1), 500, false, false));
        $this->artisan('platform:admissions-retention-prune')->expectsOutputToContain('Deleted the expired terminal applications of ')->assertSuccessful();
        $this->assertSame(0, DB::connection('pgsql_admin')->table('admission_applications')->where('id', $mapped)->count());
        $this->assertSame(3, DB::connection('pgsql_admin')->table('admission_applications')->whereIn('id', [$noEvidence, $mismatch, $foreign])->count());
    }

    #[Test]
    public function guardian_markers_are_mapped_only_from_complete_relationship_history(): void
    {
        $school = $this->school();
        $guardianEvent = fn (Guardian $g, string $at) => $this->audit($school->id, 'guardian.created', $at, Guardian::class, $g->id);
        $link = fn (Guardian $g, string $relationshipId, string $type, string $at) => $this->audit($school->id, "student_guardian.{$type}", $at, 'App\Domain\Guardians\Infrastructure\StudentGuardianRelationship', $relationshipId, ['guardianId' => $g->id]);

        $unlinked = $this->legacyGuardian($school);
        $guardianEvent($unlinked, '2019-01-01 00:00:00');
        foreach (['2019-02-01', '2019-03-01'] as $i => $day) {
            $relationship = (string) Str::uuid7();
            $link($unlinked, $relationship, 'linked', "{$day} 00:00:00");
            $link($unlinked, $relationship, 'unlinked', $i === 0 ? '2020-05-01 00:00:00' : '2021-07-01 08:00:00');
        }
        $neverLinked = $this->legacyGuardian($school);
        $guardianEvent($neverLinked, '2018-06-01 12:00:00');
        $silentEnd = $this->legacyGuardian($school);
        $guardianEvent($silentEnd, '2019-01-01 00:00:00');
        $link($silentEnd, (string) Str::uuid7(), 'linked', '2019-02-01 00:00:00');
        $noCreation = $this->legacyGuardian($school);
        $relationship = (string) Str::uuid7();
        $link($noCreation, $relationship, 'linked', '2019-02-01 00:00:00');
        $link($noCreation, $relationship, 'unlinked', '2020-02-01 00:00:00');
        $related = $this->legacyGuardian($school);
        $this->createStudentGuardianRelationship($this->createStudent($school), $related);

        $backfill = app(GuardianMarkerBackfill::class);
        $this->assertSame(['mapped' => 2, 'already_mapped' => 0, 'unresolved' => 2, 'error' => 0], $backfill->run($school, 500, true));
        $this->artisan('platform:lifecycle-markers-backfill', ['--only' => 'guardians'])->expectsOutputToContain('guardians: mapped ')->assertSuccessful();

        $this->assertSame('2021-07-01 08:00:00', $this->marker($unlinked->id), 'the LAST unlink starts the clock');
        $this->assertSame('2018-06-01 12:00:00', $this->marker($neverLinked->id), 'never linked: no relationship since creation');
        $this->assertNull($this->marker($silentEnd->id), 'a relationship that ended without an audit event: unresolved');
        $this->assertNull($this->marker($noCreation->id), 'incomplete history (no creation event): unresolved');
        $this->assertNull($this->marker($related->id), 'a related Guardian needs no marker');

        $this->assertSame(['mapped' => 0, 'already_mapped' => 2, 'unresolved' => 2, 'error' => 0], $backfill->run($school, 500, false));
        $this->artisan('platform:lifecycle-markers-backfill', ['--only' => 'nobody'])->assertFailed();
    }
}
