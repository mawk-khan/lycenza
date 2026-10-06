<?php

namespace Tests\Feature\Retention;

use App\Domain\Admissions\Application\AdmissionApplicationService;
use App\Domain\Admissions\Application\Retention\TerminalApplicationRetentionService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
use App\Support\Retention\RetentionPeriod;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.3C (E21.2G AD2/G1, project-adopted, pending legal ratification):
 * - a rejected/withdrawn application has a durable, database-owned
 *   `terminal_at` and goes 1 calendar year after it (never `updated_at`);
 *   converted ones stay with the Student core record (E21.3B);
 * - a Guardian's `no_relationship_since` is maintained by the database for
 *   every relationship writer, stops on a re-link and restarts on the next
 *   final unlink; Guardian personal data goes 1 calendar year after it,
 *   only when nothing retained needs the Guardian.
 *
 * Cases that reach the Guardian consent function keep the application clock
 * in the past relative to the real clock: its floor measures PostgreSQL's
 * real now().
 */
class AdmissionsGuardianRetentionTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = (string) config('documents.disk');
        Storage::fake($this->disk);
        config([
            'retention.admissions_terminal_years' => 1,
            'retention.guardian_years' => 1,
            'retention.authority_history_years' => 7,
            'retention.hold_school_ids' => [],
        ]);
    }

    private function at(string $moment): void
    {
        $this->travelTo(Carbon::parse($moment, 'UTC'));
    }

    private function in(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    private function rows(School $school, string $table, string $column, string $id): int
    {
        return $this->in($school, fn () => DB::table($table)->where($column, $id)->count());
    }

    private function refused(callable $statement, string $needle): void
    {
        try {
            DB::transaction(fn () => $statement());
            $this->fail("expected refusal: {$needle}");
        } catch (QueryException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function admissionsWorld(): array
    {
        $school = $this->createSchool();

        return ['school' => $school, 'campus' => $this->createCampus($school), 'grade' => $this->createGradeLevel($school), 'year' => $this->createAcademicYear($school)];
    }

    /** A terminal application whose terminal_at is given explicitly (an insert may carry a past time). */
    private function terminal(array $w, string $status, ?string $terminalAt, ?string $applicantId = null): string
    {
        $applicantId ??= $this->createApplicant($w['school'])->id;
        $id = (string) Str::uuid7();
        $this->in($w['school'], fn () => DB::table('admission_applications')->insert([
            'id' => $id, 'school_id' => $w['school']->id, 'applicant_id' => $applicantId, 'academic_year_id' => $w['year']->id, 'campus_id' => $w['campus']->id,
            'grade_level_id' => $w['grade']->id, 'status' => $status, 'terminal_at' => $terminalAt,
            // updated_at is deliberately ancient: it is never the trigger.
            'created_at' => '2001-01-01 00:00:00', 'updated_at' => '2001-01-01 00:00:00',
        ]));

        return $id;
    }

    private function guardian(School $school, ?string $since): Guardian
    {
        return $this->in($school, fn () => Guardian::factory()->create(['school_id' => $school->id, 'no_relationship_since' => $since]));
    }

    private function marker(Guardian $guardian): ?string
    {
        return $this->in($guardian->school, fn () => DB::table('guardians')->where('id', $guardian->id)->value('no_relationship_since'));
    }

    /** Contacts, a Document, consent, a preference and an old revoked account link: all of it Guardian personal data. */
    private function personalData(Guardian $guardian): string
    {
        $school = $guardian->school;
        $this->createGuardianContact($guardian, ContactType::Email, Str::random(8).'@example.test');
        $path = "schools/{$school->id}/documents/guardian/{$guardian->id}/".Str::uuid7().'.pdf';
        Storage::disk($this->disk)->put($path, 'bytes');
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->in($school, function () use ($school, $guardian, $path, $user, $membership): void {
            Document::query()->forceCreate([
                'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'guardian_id' => $guardian->id, 'classification_tier' => 'internal',
                'storage_disk' => $this->disk, 'storage_path' => $path, 'original_filename' => 'g.pdf', 'mime_type' => 'application/pdf',
                'size_bytes' => 5, 'uploaded_at' => now(), 'status' => 'active',
            ]);
            DB::table('communication_domain_consent_events')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'guardian_id' => $guardian->id, 'channel' => 'email', 'status' => 'granted',
                'recorded_at' => '2019-01-01 00:00:00', 'recorded_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('communication_domain_preferences')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'guardian_id' => $guardian->id, 'channel' => 'email', 'preference' => 'disabled', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('student_guardian_account_links')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'guardian_id' => $guardian->id, 'school_membership_id' => $membership->id,
                'status' => 'revoked', 'linked_by_user_id' => $user->id, 'linked_at' => '2010-01-01 00:00:00',
                'unlinked_by_user_id' => $user->id, 'unlinked_at' => '2011-01-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $path;
    }

    // --- Admissions ------------------------------------------------------

    #[Test]
    public function the_terminal_transition_sets_an_immutable_terminal_at_and_converted_or_live_applications_never_have_one(): void
    {
        $w = $this->admissionsWorld();
        $service = app(AdmissionApplicationService::class);
        $make = fn () => $this->createAdmissionApplication($this->createApplicant($w['school']), $w['year'], $w['campus'], $w['grade'], ['status' => 'submitted']);

        // The transition runs inside one explicit transaction whose PostgreSQL
        // transaction time is read BEFORE it: terminal_at must be exactly that
        // instant (database-owned transaction time, not a later statement's
        // clock, not the application clock). This class commits its fixtures
        // (no wrapping test transaction), so a time read in a separate, later
        // transaction could fall in the next second (OPF.2R).
        $pending = $make();
        [$tx, $rejected] = $this->in($w['school'], fn () => DB::transaction(function () use ($service, $pending): array {
            $tx = DB::selectOne("select (now() at time zone 'UTC')::text as t")->t;

            return [$tx, $service->reject($pending)];
        }));
        $storedTerminalAt = fn (): ?string => $this->in($w['school'], fn () => DB::table('admission_applications')->where('id', $rejected->id)->value(DB::raw('terminal_at::text')));
        $withdrawn = $service->withdraw($service->accept($make()));
        $accepted = $service->accept($make());

        $this->assertNotNull($rejected->terminal_at);
        $this->assertNotNull($withdrawn->terminal_at);
        $this->assertNull($accepted->terminal_at, 'a live application has no terminal time');
        $this->assertSame($tx, $storedTerminalAt(), 'set in the transition\'s own transaction, to the microsecond');

        $this->in($w['school'], function () use ($rejected, $accepted): void {
            $this->refused(fn () => DB::table('admission_applications')->where('id', $rejected->id)->update(['terminal_at' => '2001-01-01 00:00:00']), 'immutable');
            // (E21-RH.6: the guard refuses a runtime backfill before the CHECK is reached.)
            $this->refused(fn () => DB::table('admission_applications')->where('id', $accepted->id)->update(['terminal_at' => '2001-01-01 00:00:00']), 'set only by the terminal transition');
            // Touching updated_at never moves the clock.
            DB::table('admission_applications')->where('id', $rejected->id)->update(['updated_at' => '2001-01-01 00:00:00']);
        });
        $this->assertSame($rejected->terminal_at->format('Y-m-d H:i:s'), $this->in($w['school'], fn () => AdmissionApplication::query()->findOrFail($rejected->id))->terminal_at->format('Y-m-d H:i:s'));
        $this->assertSame($tx, $storedTerminalAt(), 'unchanged, to the microsecond, after the refused rewrites');
    }

    #[Test]
    public function a_terminal_application_expires_one_calendar_year_after_its_decision_with_its_applicant_when_nothing_else_remains(): void
    {
        $w = $this->admissionsWorld();
        $alone = $this->createApplicant($w['school']);
        $rejected = $this->terminal($w, 'rejected', '2025-03-10 08:00:00', $alone->id);
        $shared = $this->createApplicant($w['school']);
        $withdrawn = $this->terminal($w, 'withdrawn', '2025-03-10 08:00:00', $shared->id);
        $live = $this->createAdmissionApplication($shared, $this->createAcademicYear($w['school'], ['code' => 'AYL', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31']), $w['campus'], $w['grade'], ['status' => 'submitted']);
        $recent = $this->terminal($w, 'rejected', '2025-09-01 00:00:00');

        // Exactly one calendar year after the decision: kept (strict boundary).
        $this->at('2026-03-10 08:00:00');
        $this->artisan('platform:admissions-retention-prune')->expectsOutputToContain('Deleted the expired terminal applications of 0 applicant(s)')->assertSuccessful();

        $this->at('2026-03-10 08:00:01');
        $this->artisan('platform:admissions-retention-prune', ['--dry-run' => true])->expectsOutputToContain('Dry run: would delete the expired terminal applications of 2 applicant(s) (unresolved: 0, dependency-blocked: 0, held: 0, errors: 0)')->assertSuccessful();
        $this->assertSame(1, $this->rows($w['school'], 'admission_applications', 'id', $rejected), 'a dry run deletes nothing');

        $this->artisan('platform:admissions-retention-prune')->expectsOutputToContain('Deleted the expired terminal applications of 2 applicant(s) (unresolved: 0, dependency-blocked: 0, held: 0, errors: 0)')->assertSuccessful();
        $this->assertSame(0, $this->rows($w['school'], 'admission_applications', 'id', $rejected));
        $this->assertSame(0, $this->rows($w['school'], 'applicants', 'id', $alone->id), 'an applicant with nothing else leaves with it');
        $this->assertSame(0, $this->rows($w['school'], 'admission_applications', 'id', $withdrawn));
        $this->assertSame(1, $this->rows($w['school'], 'applicants', 'id', $shared->id), 'an applicant with a live application stays');
        $this->assertSame(1, $this->rows($w['school'], 'admission_applications', 'id', $live->id));
        $this->assertSame(1, $this->rows($w['school'], 'admission_applications', 'id', $recent));

        $this->artisan('platform:admissions-retention-prune')->expectsOutputToContain('of 0 applicant(s)')->assertSuccessful();
    }

    #[Test]
    public function converted_undated_held_and_other_school_applications_are_kept(): void
    {
        $w = $this->admissionsWorld();
        $other = $this->admissionsWorld();
        config(['retention.hold_school_ids' => [$other['school']->id]]);
        // E21-RH.6: destructive retention refuses while a configured hold is unrecorded; record it (as reconcile does).
        app(RetentionHolds::class)->place($other['school']->id, 'litigation', 'TEST-HOLD');
        $student = $this->createStudent($w['school'], ['status' => 'inactive']);
        $this->createStudentEnrollment($student, $this->createSection($w['year'], $w['campus'], $w['grade']), ['status' => 'withdrawn', 'starts_on' => $w['year']->starts_on, 'ends_on' => $w['year']->starts_on]);
        $enrollmentId = $this->in($w['school'], fn () => DB::table('student_enrollments')->where('student_id', $student->id)->value('id'));
        $converted = $this->createAdmissionApplication($this->createApplicant($w['school']), $w['year'], $w['campus'], $w['grade'], ['status' => 'converted', 'converted_student_id' => $student->id, 'converted_student_enrollment_id' => $enrollmentId, 'converted_at' => '2020-01-01 00:00:00']);
        $held = $this->terminal($other, 'rejected', '2020-01-01 00:00:00');

        $this->at('2030-01-01 00:00:00');
        // Exact per-School counts through the service (the command walks every School in the database).
        $service = app(TerminalApplicationRetentionService::class);
        $cutoff = RetentionPeriod::yearsBeforeNow(1);
        $this->assertSame(['eligible' => 0, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0, 'held' => 0], $service->prune($w['school'], $cutoff, 500, true, false));
        $this->assertSame(['eligible' => 1, 'deleted' => 0, 'unresolved' => 0, 'dependency_blocked' => 0, 'errors' => 0, 'held' => 1], $service->prune($other['school'], $cutoff, 500, false, true));
        $this->artisan('platform:admissions-retention-prune')->expectsOutputToContain('held: ')->assertSuccessful();

        $this->assertSame(1, $this->rows($w['school'], 'admission_applications', 'id', $converted->id), 'converted: Student core record (E21.3B)');
        $this->assertSame(1, $this->rows($other['school'], 'admission_applications', 'id', $held));
        $this->assertSame(0, $this->in($w['school'], fn () => DB::table('admission_applications')->where('id', $held)->count()), 'School A never sees School B (RLS)');
    }

    // --- Guardian marker -------------------------------------------------

    #[Test]
    public function the_marker_stops_on_any_relationship_and_restarts_only_when_the_last_one_ends(): void
    {
        $school = $this->createSchool();
        $guardian = $this->createGuardian($school);
        $this->assertNotNull($this->marker($guardian), 'a Guardian created without a relationship starts its clock at creation');

        $service = app(StudentGuardianRelationshipService::class);
        $first = $service->link($this->createStudent($school), $guardian, RelationshipType::Mother);
        $this->assertNull($this->marker($guardian), 'a relationship stops the clock');
        $second = $service->link($this->createStudent($school), $guardian, RelationshipType::Mother);

        $service->unlink($first);
        $this->assertNull($this->marker($guardian), 'another Student is still related');
        $service->unlink($second);
        $ended = $this->marker($guardian);
        $this->assertNotNull($ended, 'the last relationship ended: the clock starts');

        $third = $service->link($this->createStudent($school), $guardian, RelationshipType::Mother);
        $this->assertNull($this->marker($guardian), 're-linked: the clock stops');
        $service->unlink($third);
        $this->assertGreaterThan($ended, $this->marker($guardian), 'the next final unlink restarts the clock from its own time');

        // Only the relationship trigger or a past-dated backfill of an unresolved Guardian may write it.
        $this->in($school, function () use ($guardian, $school): void {
            $this->refused(fn () => DB::table('guardians')->where('id', $guardian->id)->update(['no_relationship_since' => '2001-01-01 00:00:00']), 'maintained by the relationship trigger');
            $unresolved = $this->guardian($school, null);
            $this->refused(fn () => DB::table('guardians')->where('id', $unresolved->id)->update(['no_relationship_since' => '2099-01-01 00:00:00']), 'maintained by the relationship trigger');
            // E21-RH.6: a past-dated backfill is the schema owner's alone (the runtime role could otherwise age a
            // Guardian into eligibility); the operator command runs on the maintenance connection.
            $this->refused(fn () => DB::table('guardians')->where('id', $unresolved->id)->update(['no_relationship_since' => '2020-01-01 00:00:00']), 'maintained by the relationship trigger');
            $admin = DB::connection('pgsql_admin');
            $this->assertSame(1, $admin->transaction(function () use ($admin, $school, $unresolved): int {
                $admin->select("select set_config('app.current_school_id', ?, true)", [$school->id]);

                return $admin->table('guardians')->where('id', $unresolved->id)->update(['no_relationship_since' => '2020-01-01 00:00:00']);
            }));
        });
    }

    // --- Guardian retention ----------------------------------------------

    #[Test]
    public function guardian_personal_data_goes_one_calendar_year_after_the_last_relationship_ended(): void
    {
        $school = $this->createSchool();
        $gone = $this->guardian($school, '2025-01-15 10:00:00');
        $path = $this->personalData($gone);
        $boundary = $this->guardian($school, '2025-01-15 10:00:01');
        $related = $this->guardian($school, null);
        $this->createStudentGuardianRelationship($this->createStudent($school), $related);
        $unmarked = $this->guardian($school, null);
        $audit = $this->in($school, fn () => DB::table('school_audit_events')->count());

        $this->at('2026-01-15 10:00:01');
        $this->artisan('platform:guardian-retention-prune', ['--dry-run' => true])->expectsOutputToContain('Dry run: would delete the personal data of 1 Guardian(s) (unresolved: 1, dependency-blocked: 0, held: 0, errors: 0)')->assertSuccessful();
        $this->assertSame(1, $this->rows($school, 'guardians', 'id', $gone->id), 'a dry run deletes nothing');

        $this->artisan('platform:guardian-retention-prune')->expectsOutputToContain('Deleted the personal data of 1 Guardian(s) (unresolved: 1, dependency-blocked: 0, held: 0, errors: 0)')->assertSuccessful();
        foreach (['guardians' => 'id', 'guardian_contacts' => 'guardian_id', 'documents' => 'guardian_id', 'communication_domain_consent_events' => 'guardian_id', 'communication_domain_preferences' => 'guardian_id', 'student_guardian_account_links' => 'guardian_id'] as $table => $column) {
            $this->assertSame(0, $this->rows($school, $table, $column, $gone->id), "{$table} goes with the Guardian");
        }
        Storage::disk($this->disk)->assertMissing($path);
        $this->assertSame(1, $this->rows($school, 'guardians', 'id', $boundary->id), 'exactly one calendar year: kept');
        $this->assertSame(1, $this->rows($school, 'guardians', 'id', $related->id), 'a related Guardian has no clock');
        $this->assertSame(1, $this->rows($school, 'guardians', 'id', $unmarked->id), 'an unresolved Guardian is kept');
        $this->assertSame($audit, $this->in($school, fn () => DB::table('school_audit_events')->count()), 'the audit ledger is untouched');

        $this->at('2026-01-15 10:00:02');
        $this->artisan('platform:guardian-retention-prune')->expectsOutputToContain('of 1 Guardian(s)')->assertSuccessful();
        $this->assertSame(0, $this->rows($school, 'guardians', 'id', $boundary->id), 'one second later it goes too');
        $this->artisan('platform:guardian-retention-prune')->expectsOutputToContain('of 0 Guardian(s)')->assertSuccessful();
    }

    #[Test]
    public function account_links_retained_records_and_holds_keep_the_guardian(): void
    {
        $school = $this->createSchool();
        $other = $this->createSchool();
        $user = $this->createUser();
        $link = function (Guardian $guardian, string $status, ?string $unlinkedAt) use ($user): void {
            $membership = $this->createMembership($this->createUser(), $guardian->school);
            $this->in($guardian->school, fn () => DB::table('student_guardian_account_links')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $guardian->school_id, 'guardian_id' => $guardian->id, 'school_membership_id' => $membership->id,
                'status' => $status, 'linked_by_user_id' => $user->id, 'linked_at' => '2010-01-01 00:00:00', 'unlinked_by_user_id' => $unlinkedAt === null ? null : $user->id,
                'unlinked_at' => $unlinkedAt, 'created_at' => now(), 'updated_at' => now(),
            ]));
        };
        $active = $this->guardian($school, '2020-01-01 00:00:00');
        $link($active, 'active', null);
        $recentlyRevoked = $this->guardian($school, '2020-01-01 00:00:00');
        $link($recentlyRevoked, 'revoked', '2024-01-01 00:00:00');
        $invited = $this->guardian($school, '2020-01-01 00:00:00');
        $this->in($school, fn () => DB::table('identity_account_invitations')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'guardian_id' => $invited->id, 'token_hash' => hash('sha256', Str::random(40)),
            'destination_email_hash' => hash('sha256', Str::random(12)), 'status' => 'pending', 'expires_at' => '2099-01-01 00:00:00',
            'invited_by_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
        ]));
        config(['retention.hold_school_ids' => [$other->id]]);
        // E21-RH.6: destructive retention refuses while a configured hold is unrecorded; record it (reconcile).
        app(RetentionHolds::class)->place($other->id, 'litigation', 'GUARDIAN-HOLD');
        $held = $this->guardian($other, '2020-01-01 00:00:00');

        $this->at('2026-06-15 12:00:00');
        $this->artisan('platform:guardian-retention-prune')->expectsOutputToContain('Deleted the personal data of 0 Guardian(s) (unresolved: 0, dependency-blocked: 3, held: 1, errors: 0)')->assertSuccessful();
        foreach ([$active, $recentlyRevoked, $invited] as $guardian) {
            $this->assertSame(1, $this->rows($school, 'guardians', 'id', $guardian->id));
        }
        $this->assertSame(1, $this->rows($other, 'guardians', 'id', $held->id));

        // Without an authority period every link keeps its Guardian; nothing is ever unlinked to make room.
        config(['retention.authority_history_years' => null]);
        $old = $this->guardian($school, '2020-01-01 00:00:00');
        $link($old, 'revoked', '2011-01-01 00:00:00');
        $this->artisan('platform:guardian-retention-prune')->expectsOutputToContain('dependency-blocked: 4')->assertSuccessful();
        $this->assertSame('active', $this->in($school, fn () => DB::table('student_guardian_account_links')->where('guardian_id', $active->id)->value('status')));
    }

    #[Test]
    public function a_relationship_named_by_retained_student_evidence_keeps_the_guardian_related(): void
    {
        // E21.3B: a guardian-consent processing authorization keeps its
        // relationship with the Student core record, so the Guardian stays
        // related (no clock) until that core record goes.
        $school = $this->createSchool();
        $guardian = $this->guardian($school, null);
        $student = $this->createStudent($school, ['status' => 'inactive']);
        $relationship = $this->createStudentGuardianRelationship($student, $guardian);
        $this->in($school, fn () => DB::table('student_processing_authorizations')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'student_id' => $student->id, 'purpose' => 'academic_records',
            'basis_type' => 'guardian_consent', 'status' => 'recorded', 'student_guardian_relationship_id' => $relationship->id,
            'recorded_at' => '2000-06-01 00:00:00', 'recorded_by_user_id' => $this->createUser()->id, 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->at('2026-06-15 12:00:00');
        $this->artisan('platform:guardian-retention-prune')->expectsOutputToContain('of 0 Guardian(s) (unresolved: 0')->assertSuccessful();
        $this->assertSame(1, $this->rows($school, 'guardians', 'id', $guardian->id));
        $this->assertNull($this->marker($guardian));
    }

    #[Test]
    public function the_guardian_consent_function_refuses_direct_deletes_young_or_related_guardians_and_other_schools(): void
    {
        $school = $this->createSchool();
        $b = $this->createSchool();
        $old = $this->guardian($school, '2020-01-01 00:00:00');
        $this->personalData($old);
        $young = $this->guardian($school, now('UTC')->subMonths(3)->format('Y-m-d H:i:s'));
        $related = $this->guardian($school, null);
        $this->createStudentGuardianRelationship($this->createStudent($school), $related);
        $expiry = app(RetentionExpiry::class);
        $cutoff = Carbon::parse('2024-01-01 00:00:00', 'UTC');

        $this->in($school, function () use ($school, $old, $expiry, $cutoff): void {
            $this->refused(fn () => DB::table('communication_domain_consent_events')->where('guardian_id', $old->id)->delete(), 'permission denied');
            // E21-RH.6: the runtime role cannot run the function at all.
            $this->refused(fn () => $expiry->guardianConsentEvents($school, $old->id, $cutoff, true), 'permission denied for function');
        });
        // As the retention identity, the database still refuses every ineligible unit.
        DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, fn () => $this->in($school, function () use ($school, $old, $young, $related, $expiry, $cutoff): void {
            $this->refused(fn () => $expiry->guardianConsentEvents($school, $old->id, now('UTC')->subMonths(6), true), 'retention_floor');
            $this->refused(fn () => $expiry->guardianConsentEvents($school, $young->id, $cutoff, true), 'retention_guardian');
            $this->refused(fn () => $expiry->guardianConsentEvents($school, $related->id, $cutoff, true), 'retention_guardian');
            $this->assertSame(1, DB::transaction(fn () => $expiry->guardianConsentEvents($school, $old->id, $cutoff, true)));
        }));
        DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, fn () => $this->in($b, fn () => $this->refused(fn () => $expiry->guardianConsentEvents($school, $old->id, $cutoff, false), 'retention_tenant')));

        $this->assertSame(1, $this->rows($school, 'communication_domain_consent_events', 'guardian_id', $old->id));
        $check = collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');
        $this->assertSame(CheckResult::PASS, $check['retention_functions_narrow']->status);
    }

    #[Test]
    public function the_guardian_purge_removes_document_bytes_from_real_minio(): void
    {
        config(['documents.disk' => 's3']);
        $school = $this->createSchool();
        $guardian = $this->guardian($school, '2020-01-01 00:00:00');
        $path = "schools/{$school->id}/documents/guardian/{$guardian->id}/".Str::uuid7().'.pdf';
        Storage::disk('s3')->put($path, 'bytes');
        $this->in($school, fn () => Document::query()->forceCreate([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'guardian_id' => $guardian->id, 'classification_tier' => 'internal',
            'storage_disk' => 's3', 'storage_path' => $path, 'original_filename' => 'g.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 5, 'uploaded_at' => now(), 'status' => 'active',
        ]));
        $kept = $this->guardian($school, null);
        $this->createStudentGuardianRelationship($this->createStudent($school), $kept);
        $keptPath = "schools/{$school->id}/documents/guardian/{$kept->id}/".Str::uuid7().'.pdf';
        Storage::disk('s3')->put($keptPath, 'bytes');
        $this->in($school, fn () => Document::query()->forceCreate([
            'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'guardian_id' => $kept->id, 'classification_tier' => 'internal',
            'storage_disk' => 's3', 'storage_path' => $keptPath, 'original_filename' => 'k.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 5, 'uploaded_at' => now(), 'status' => 'active',
        ]));

        try {
            $this->at('2026-06-15 12:00:00');
            $this->artisan('platform:guardian-retention-prune')->expectsOutputToContain('Deleted the personal data of 1 Guardian(s) (unresolved: 0, dependency-blocked: 0, held: 0, errors: 0)')->assertSuccessful();
            $this->assertFalse(Storage::disk('s3')->exists($path));
            $this->assertTrue(Storage::disk('s3')->exists($keptPath), 'a retained Guardian keeps its bytes');
        } finally {
            Storage::disk('s3')->delete([$path, $keptPath]);
        }
    }
}
