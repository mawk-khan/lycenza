<?php

namespace Tests\Feature\Retention;

use App\Domain\Attendance\Application\AttendanceSubmissionService;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Students\Application\Retention\StudentRetentionEligibility;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * E21.2D (E21-D7, project-adopted, pending legal ratification): Student
 * operational history goes 7 calendar years after the Student's final exit;
 * the core academic record goes 25 years after it. Only when the exit is
 * unambiguous, the School is not held, and nothing else still needs the
 * Student.
 */
class StudentRetentionPruneTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesAttendanceFixtures, CreatesFeesFixtures, CreatesFinanceFixtures;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = (string) config('documents.disk');
        Storage::fake($this->disk);
        config([
            'retention.student_operational_years' => 7,
            'retention.student_core_years' => 25,
            'retention.authority_history_years' => 7,
            'retention.hold_school_ids' => [],
        ]);
    }

    private function at(string $date): void
    {
        $this->travelTo(Carbon::parse($date.' 12:00:00', 'UTC'));
    }

    /** @return array<string, mixed> a School with one Section and Offering */
    private function world(): array
    {
        $w = $this->attendanceWorld();
        $w['yearNext'] = $this->createAcademicYear($w['school'], ['starts_on' => '2027-04-01', 'ends_on' => '2028-03-31', 'status' => 'draft', 'code' => 'AYNEXT']);
        $w['sectionNext'] = $this->createSection($w['yearNext'], $w['campus'], $w['grade'], ['status' => 'active', 'code' => 'N']);

        return $w;
    }

    /** An inactive Student whose only placement ended on $endsOn. */
    private function leaver(array $w, string $endsOn, string $status = 'withdrawn', string $studentStatus = 'inactive'): Student
    {
        $student = $this->createStudent($w['school'], ['status' => $studentStatus]);
        $this->createStudentEnrollment($student, $w['section'], ['status' => $status, 'starts_on' => '2026-06-01', 'ends_on' => $endsOn]);

        // E21-RH.6: the end was also RECORDED back then (the database counts from the later of the two).
        if (method_exists($this, 'backdateEndRecording')) {
            $this->backdateEndRecording();
        }

        return $student;
    }

    /** A Guardian (with a contact) related to $student. */
    private function guardianOf(Student $student): string
    {
        $guardian = $this->createGuardian($student->school);
        $this->createGuardianContact($guardian, ContactType::Email, Str::random(8).'@example.test');
        $this->createStudentGuardianRelationship($student, $guardian);

        return $guardian->id;
    }

    private function documentOf(Student $student): string
    {
        $path = "schools/{$student->school_id}/documents/student/{$student->id}/".Str::uuid7().'.pdf';
        Storage::disk($this->disk)->put($path, 'bytes');
        $this->inSchool($student->school, fn () => Document::query()->forceCreate([
            'id' => (string) Str::uuid7(), 'school_id' => $student->school_id, 'student_id' => $student->id, 'classification_tier' => 'internal',
            'storage_disk' => $this->disk, 'storage_path' => $path, 'original_filename' => 'x.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 5, 'uploaded_at' => now(), 'status' => 'archived',
        ]));

        return $path;
    }

    private function accountLink(Student $student, string $status, ?string $unlinkedAt): void
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $student->school);
        $this->inSchool($student->school, fn () => DB::table('student_guardian_account_links')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $student->school_id, 'student_id' => $student->id, 'school_membership_id' => $membership->id,
            'status' => $status, 'linked_by_user_id' => $user->id, 'linked_at' => '2026-06-01 00:00:00',
            'unlinked_by_user_id' => $unlinkedAt === null ? null : $user->id, 'unlinked_at' => $unlinkedAt, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    private function has(School $school, string $table, string $column, string $id): bool
    {
        return $this->inSchool($school, fn () => DB::table($table)->where($column, $id)->exists());
    }

    private function prune(array $options = []): PendingCommand
    {
        return $this->artisan('platform:student-retention-prune', $options);
    }

    #[Test]
    public function nothing_is_deleted_while_unconfigured_and_a_shorter_core_period_is_refused(): void
    {
        $w = $this->world();
        $student = $this->leaver($w, '2026-09-30');
        $this->guardianOf($student);
        $this->at('2090-01-01');

        config(['retention.student_operational_years' => null, 'retention.student_core_years' => null]);
        $this->prune()->expectsOutputToContain('not configured')->assertSuccessful();

        config(['retention.student_operational_years' => 7, 'retention.student_core_years' => 5]);
        $this->prune()->assertFailed();

        $this->assertTrue($this->has($w['school'], 'student_guardian_relationships', 'student_id', $student->id));
        $this->assertTrue($this->has($w['school'], 'students', 'id', $student->id));
    }

    #[Test]
    public function operational_history_expires_seven_calendar_years_after_final_exit(): void
    {
        $w = $this->world();
        $leaving = $this->enrollStudent($w['section'], '1', self::MONDAY);
        $staying = $this->enrollStudent($w['section'], '2', self::MONDAY);
        $service = app(AttendanceSubmissionService::class);
        $this->inSchool($w['school'], fn () => $service->guarded(fn () => DB::transaction(fn () => $service->submit(
            $w['school'], $w['entry']->id, self::MONDAY,
            [['student_enrollment_id' => $leaving->id, 'status' => 'present'], ['student_enrollment_id' => $staying->id, 'status' => 'absent']],
            $w['actor'],
        ))));
        app(StudentEnrollmentService::class)->withdraw($leaving, '2026-09-30');
        $this->inSchool($w['school'], fn () => DB::table('students')->where('id', $leaving->student_id)->update(['status' => 'inactive']));
        $student = $this->inSchool($w['school'], fn () => Student::query()->findOrFail($leaving->student_id));
        $guardianId = $this->guardianOf($student);
        $plan = $this->createEnrollmentRolloverPlan($w['year'], $w['yearNext']);
        $this->createEnrollmentRolloverItem($plan, $student, $this->inSchool($w['school'], fn () => $leaving->fresh()));
        $audit = $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->count());

        // Exactly seven years after the exit day: kept (strict boundary).
        $this->at('2033-09-30');
        $this->prune(['--only' => 'operational'])->expectsOutputToContain('attendance of 0 Student(s), rollover items of 0, Guardian relationships of 0')->assertSuccessful();
        $this->assertTrue($this->has($w['school'], 'attendance_records', 'student_enrollment_id', $leaving->id));

        $this->at('2033-10-01');
        $this->prune(['--only' => 'operational'])->expectsOutputToContain('attendance of 1 Student(s), rollover items of 1, Guardian relationships of 1')->assertSuccessful();

        $this->assertFalse($this->has($w['school'], 'attendance_records', 'student_enrollment_id', $leaving->id));
        $this->assertTrue($this->has($w['school'], 'attendance_records', 'student_enrollment_id', $staying->id), 'a current Student keeps its attendance');
        $this->assertSame(1, $this->inSchool($w['school'], fn () => DB::table('attendance_sessions')->count()), 'the register header stays');
        $this->assertFalse($this->has($w['school'], 'enrollment_rollover_items', 'student_id', $student->id));
        $this->assertFalse($this->has($w['school'], 'student_guardian_relationships', 'student_id', $student->id));
        // The Guardian's own data, the core record and the audit ledger are untouched.
        $this->assertTrue($this->has($w['school'], 'guardians', 'id', $guardianId));
        $this->assertTrue($this->has($w['school'], 'guardian_contacts', 'guardian_id', $guardianId));
        $this->assertTrue($this->has($w['school'], 'students', 'id', $student->id));
        $this->assertTrue($this->has($w['school'], 'student_enrollments', 'id', $leaving->id));
        $this->assertSame($audit, $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->count()));
    }

    #[Test]
    public function re_entry_and_reactivation_restart_or_stop_the_clock(): void
    {
        $w = $this->world();
        $reEnrolled = $this->leaver($w, '2026-09-30');
        $this->createStudentEnrollment($reEnrolled, $w['sectionNext'], ['status' => 'active', 'starts_on' => '2027-04-01', 'ends_on' => null]);
        $reactivated = $this->leaver($w, '2026-09-30', studentStatus: 'active');
        $returnedAndLeft = $this->leaver($w, '2026-09-30');
        $this->createStudentEnrollment($returnedAndLeft, $w['sectionNext'], ['status' => 'completed', 'starts_on' => '2027-04-01', 'ends_on' => '2030-03-31']);
        foreach ([$reEnrolled, $reactivated, $returnedAndLeft] as $student) {
            $this->guardianOf($student);
        }

        $this->at('2033-10-01');
        $this->prune(['--only' => 'operational'])->expectsOutputToContain('Guardian relationships of 0')->assertSuccessful();

        // The clock for the returning Student started at its LAST departure (2030-03-31).
        $this->at('2037-04-01');
        $this->prune(['--only' => 'operational'])->expectsOutputToContain('Guardian relationships of 1')->assertSuccessful();
        $this->assertFalse($this->has($w['school'], 'student_guardian_relationships', 'student_id', $returnedAndLeft->id));
        $this->assertTrue($this->has($w['school'], 'student_guardian_relationships', 'student_id', $reEnrolled->id));
        $this->assertTrue($this->has($w['school'], 'student_guardian_relationships', 'student_id', $reactivated->id));
    }

    #[Test]
    public function an_ambiguous_exit_keeps_everything_and_is_reported_unresolved(): void
    {
        $w = $this->world();
        $neverPlaced = $this->createStudent($w['school'], ['status' => 'inactive']);
        $onlyCancelled = $this->leaver($w, '2026-09-30', 'cancelled');
        $lastTransferred = $this->leaver($w, '2026-09-30', 'transferred');
        foreach ([$neverPlaced, $onlyCancelled, $lastTransferred] as $student) {
            $this->guardianOf($student);
            $this->documentOf($student);
        }

        $this->at('2090-01-01');
        $this->prune()->expectsOutputToContain('Guardian relationships of 0 (unresolved exit: 3')
            ->expectsOutputToContain('core record of 0 Student(s) (unresolved exit: 3')->assertSuccessful();

        foreach ([$neverPlaced, $onlyCancelled, $lastTransferred] as $student) {
            $this->assertTrue($this->has($w['school'], 'students', 'id', $student->id));
            $this->assertTrue($this->has($w['school'], 'student_guardian_relationships', 'student_id', $student->id));
            $this->assertTrue($this->has($w['school'], 'documents', 'student_id', $student->id));
        }
    }

    #[Test]
    public function the_core_record_goes_25_years_after_exit_only_when_nothing_else_needs_the_student(): void
    {
        $w = $this->world();
        $free = $this->leaver($w, '2026-09-30');
        $this->createStudentSubjectEnrollment($free, $w['offering'], ['status' => 'withdrawn', 'starts_on' => '2026-06-01', 'ends_on' => '2026-09-30']);
        $freeGuardian = $this->guardianOf($free);
        $freeDocument = $this->documentOf($free);
        $this->accountLink($free, 'revoked', '2026-10-01 00:00:00');

        // An unreturned Library loan (ON DELETE CASCADE from students): an open
        // relationship keeps its Student and is never cascaded away (E21.3B).
        $borrower = $this->leaver($w, '2026-09-30');
        $this->inSchool($w['school'], function () use ($w, $borrower): void {
            $title = (string) Str::uuid7();
            $copy = (string) Str::uuid7();
            DB::table('library_titles')->insert(['id' => $title, 'school_id' => $w['school']->id, 'title' => 'Atlas', 'status' => 'active']);
            DB::table('library_copies')->insert(['id' => $copy, 'school_id' => $w['school']->id, 'library_title_id' => $title, 'code' => 'C1', 'status' => 'active']);
            DB::table('library_loans')->insert(['id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'library_copy_id' => $copy, 'student_id' => $borrower->id,
                'status' => 'active', 'checked_out_at' => '2026-07-01 09:00:00', 'due_at' => '2026-07-15 09:00:00', 'checked_in_at' => null]);
        });

        // A usable Student-subject portal invitation keeps its Student too.
        $invited = $this->leaver($w, '2026-09-30');
        $this->inSchool($w['school'], fn () => DB::table('identity_account_invitations')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'student_id' => $invited->id, 'token_hash' => hash('sha256', Str::random(40)),
            'destination_email_hash' => hash('sha256', Str::random(12)), 'status' => 'pending', 'expires_at' => '2099-01-01 00:00:00',
            'invited_by_user_id' => $this->createUser()->id, 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->at('2051-09-30');
        $this->prune()->expectsOutputToContain('core record of 0 Student(s)')->assertSuccessful();
        $this->assertTrue($this->has($w['school'], 'students', 'id', $free->id), 'exactly 25 years: kept');

        $this->at('2051-10-01');
        $this->prune()->expectsOutputToContain('core record of 1 Student(s) (unresolved exit: 0, dependency-blocked: 2')->assertSuccessful();

        foreach (['students' => 'id', 'student_enrollments' => 'student_id', 'student_subject_enrollments' => 'student_id', 'documents' => 'student_id', 'student_guardian_account_links' => 'student_id'] as $table => $column) {
            $this->assertFalse($this->has($w['school'], $table, $column, $free->id), "{$table} goes with the core record");
        }
        Storage::disk($this->disk)->assertMissing($freeDocument);
        $this->assertTrue($this->has($w['school'], 'guardians', 'id', $freeGuardian), 'Guardian personal data has no adopted period: kept');

        $this->assertTrue($this->has($w['school'], 'students', 'id', $borrower->id));
        $this->assertTrue($this->has($w['school'], 'library_loans', 'student_id', $borrower->id));
        $this->assertTrue($this->has($w['school'], 'students', 'id', $invited->id));
        $this->assertTrue($this->has($w['school'], 'identity_account_invitations', 'student_id', $invited->id));
    }

    #[Test]
    public function a_student_account_link_keeps_the_record_until_its_authority_period_has_passed(): void
    {
        $w = $this->world();
        $active = $this->leaver($w, '2026-09-30');
        $this->accountLink($active, 'active', null);
        $recent = $this->leaver($w, '2026-09-30');
        $this->accountLink($recent, 'revoked', '2048-01-01 00:00:00');
        $old = $this->leaver($w, '2026-09-30');
        $this->accountLink($old, 'revoked', '2027-01-01 00:00:00');

        $this->at('2051-10-01');
        config(['retention.authority_history_years' => null]);
        $this->prune(['--only' => 'core'])->expectsOutputToContain('core record of 0 Student(s) (unresolved exit: 0, dependency-blocked: 3')->assertSuccessful();

        config(['retention.authority_history_years' => 7]);
        $this->prune(['--only' => 'core'])->expectsOutputToContain('core record of 1 Student(s) (unresolved exit: 0, dependency-blocked: 2')->assertSuccessful();
        $this->assertFalse($this->has($w['school'], 'students', 'id', $old->id));
        $this->assertTrue($this->has($w['school'], 'students', 'id', $active->id));
        $this->assertTrue($this->has($w['school'], 'students', 'id', $recent->id));
    }

    #[Test]
    public function the_core_record_waits_for_its_operational_rows(): void
    {
        $w = $this->world();
        $student = $this->leaver($w, '2026-09-30');
        $this->guardianOf($student);

        $this->at('2051-10-01');
        $this->prune(['--only' => 'core'])->expectsOutputToContain('core record of 0 Student(s) (unresolved exit: 0, dependency-blocked: 1')->assertSuccessful();
        $this->assertTrue($this->has($w['school'], 'students', 'id', $student->id));

        // One full run expires the operational rows first, then the core record.
        $this->prune()->expectsOutputToContain('core record of 1 Student(s)')->assertSuccessful();
        $this->assertFalse($this->has($w['school'], 'students', 'id', $student->id));
    }

    #[Test]
    public function a_held_school_keeps_everything_and_another_school_is_unaffected_by_it(): void
    {
        $held = $this->world();
        $other = $this->world();
        config(['retention.hold_school_ids' => [$held['school']->id]]);
        // E21-RH.6: destructive retention refuses while a configured hold is unrecorded; record it (as reconcile does).
        app(RetentionHolds::class)->place($held['school']->id, 'litigation', 'TEST-HOLD');
        $heldStudent = $this->leaver($held, '2026-09-30');
        $this->guardianOf($heldStudent);
        $heldDocument = $this->documentOf($heldStudent);
        $otherStudent = $this->leaver($other, '2026-09-30');
        $this->guardianOf($otherStudent);
        $otherDocument = $this->documentOf($otherStudent);

        $this->at('2051-10-01');
        $this->prune(['--dry-run' => true])->expectsOutputToContain('Dry run: would delete the core record of 1 Student(s) (unresolved exit: 0, dependency-blocked: 0, held: 1')->assertSuccessful();
        $this->assertTrue($this->has($other['school'], 'students', 'id', $otherStudent->id), 'a dry run deletes nothing');

        $this->prune()->expectsOutputToContain('Guardian relationships of 1 (unresolved exit: 0, dependency-blocked: 0, held: 1')
            ->expectsOutputToContain('core record of 1 Student(s) (unresolved exit: 0, dependency-blocked: 0, held: 1')->assertSuccessful();

        $this->assertTrue($this->has($held['school'], 'students', 'id', $heldStudent->id));
        $this->assertTrue($this->has($held['school'], 'student_guardian_relationships', 'student_id', $heldStudent->id));
        $this->assertTrue($this->has($held['school'], 'documents', 'student_id', $heldStudent->id));
        Storage::disk($this->disk)->assertExists($heldDocument);
        $this->assertFalse($this->has($other['school'], 'students', 'id', $otherStudent->id));
        Storage::disk($this->disk)->assertMissing($otherDocument);
    }

    #[Test]
    public function a_leap_day_exit_expires_on_the_first_of_march(): void
    {
        $w = $this->world();
        $student = $this->leaver($w, '2028-02-29');
        $this->guardianOf($student);

        $this->at('2035-02-28');
        $this->prune(['--only' => 'operational'])->expectsOutputToContain('Guardian relationships of 0')->assertSuccessful();

        $this->at('2035-03-01');
        $this->prune(['--only' => 'operational'])->expectsOutputToContain('Guardian relationships of 1')->assertSuccessful();
        $this->assertFalse($this->has($w['school'], 'student_guardian_relationships', 'student_id', $student->id));
    }

    #[Test]
    public function a_failed_byte_delete_is_counted_and_left_to_the_orphan_run(): void
    {
        $w = $this->world();
        $student = $this->leaver($w, '2026-09-30');
        $path = $this->documentOf($student);
        $fake = Storage::disk($this->disk);
        Storage::set($this->disk, new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function delete($paths)
            {
                return false;
            }
        });

        $this->at('2051-10-01');
        $this->prune(['--only' => 'core'])->expectsOutputToContain('core record of 1 Student(s) (unresolved exit: 0, dependency-blocked: 0, held: 0, errors: 1)')->assertSuccessful();

        $this->assertFalse($this->has($w['school'], 'documents', 'student_id', $student->id));
        $this->assertTrue($fake->exists($path), 'unreferenced now: platform:storage-orphans-prune removes it (E21-D5)');
    }

    #[Test]
    public function a_purge_only_ever_sees_its_own_school(): void
    {
        $a = $this->world();
        $b = $this->world();
        $studentOfB = $this->leaver($b, '2026-09-30');
        $eligibility = app(StudentRetentionEligibility::class);

        // Under School A's context, B's Student does not exist (RLS), so nothing of it can be purged.
        // E21-RH.6: the unit lock is the retention identity's (lock-only definer), so this runs on its connection.
        DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, function () use ($a, $b, $eligibility, $studentOfB): void {
            $this->assertNull($this->inSchool($a['school'], fn () => DB::transaction(fn () => $eligibility->lockExit($studentOfB->id))));
            $this->assertSame('2026-09-30', $this->inSchool($b['school'], fn () => DB::transaction(fn () => $eligibility->lockExit($studentOfB->id)))?->exitDate);
        });
    }

    #[Test]
    public function guardian_owned_data_survives_the_relationship_expiry(): void
    {
        $w = $this->world();
        $student = $this->leaver($w, '2026-09-30');
        $guardianId = $this->guardianOf($student);
        $path = "schools/{$w['school']->id}/documents/guardian/{$guardianId}/".Str::uuid7().'.pdf';
        Storage::disk($this->disk)->put($path, 'bytes');
        $this->inSchool($w['school'], fn () => Document::query()->forceCreate([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'guardian_id' => $guardianId, 'classification_tier' => 'internal',
            'storage_disk' => $this->disk, 'storage_path' => $path, 'original_filename' => 'g.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 5, 'uploaded_at' => now(), 'status' => 'active',
        ]));

        $this->at('2051-10-01');
        $this->prune()->expectsOutputToContain('core record of 1 Student(s)')->assertSuccessful();

        $this->assertTrue($this->has($w['school'], 'documents', 'guardian_id', $guardianId));
        Storage::disk($this->disk)->assertExists($path);
    }

    #[Test]
    public function the_core_purge_removes_student_document_bytes_from_real_minio(): void
    {
        config(['documents.disk' => 's3']);
        $w = $this->world();
        $student = $this->leaver($w, '2026-09-30');
        $path = "schools/{$w['school']->id}/documents/student/{$student->id}/".Str::uuid7().'.pdf';
        Storage::disk('s3')->put($path, 'bytes');
        $this->inSchool($w['school'], fn () => Document::query()->forceCreate([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'student_id' => $student->id, 'classification_tier' => 'internal',
            'storage_disk' => 's3', 'storage_path' => $path, 'original_filename' => 's.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => 5, 'uploaded_at' => now(), 'status' => 'active',
        ]));

        try {
            $this->at('2051-10-01');
            $this->prune(['--only' => 'core'])->expectsOutputToContain('core record of 1 Student(s) (unresolved exit: 0, dependency-blocked: 0, held: 0, errors: 0)')->assertSuccessful();
            $this->assertFalse(Storage::disk('s3')->exists($path));
        } finally {
            Storage::disk('s3')->delete($path);
        }
    }

    #[Test]
    public function a_retained_finance_record_keeps_its_student_and_is_never_touched(): void
    {
        // E21.2E: Finance (D8) has no expiry while every balance is derived from
        // all postings, so a Student with a charge stays dependency-blocked. The
        // Student run never deletes, cascades or rewrites a Finance row.
        $w = $this->world();
        $student = $this->leaver($w, '2026-09-30');
        $charge = $this->assessCharge($w['school'], $student, $w['year'], $this->createLedgerAccount($w['school'], ['type' => 'asset']), $this->createLedgerAccount($w['school'], ['type' => 'income']), '300.00');
        $ledger = fn () => $this->inSchool($w['school'], fn () => [DB::table('charges')->count(), DB::table('journal_entries')->count(), DB::table('journal_lines')->count()]);
        $before = $ledger();

        $this->at('2090-01-01');
        $this->prune()->expectsOutputToContain('core record of 0 Student(s) (unresolved exit: 0, dependency-blocked: 1')->assertSuccessful();

        $this->assertTrue($this->has($w['school'], 'students', 'id', $student->id));
        $this->assertTrue($this->has($w['school'], 'charges', 'id', $charge->id));
        $this->assertSame($before, $ledger());
    }
}
