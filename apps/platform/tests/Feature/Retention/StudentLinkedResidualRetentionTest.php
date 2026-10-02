<?php

namespace Tests\Feature\Retention;

use App\Domain\Students\Infrastructure\Student;
use App\Models\School;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Retention\RetentionExpiry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.3B (E21-D7, E21.2G O1/P1/AD1/C4/C5, project-adopted, pending legal
 * ratification):
 * - returned Library loans and ended Transport/Hostel assignments are D7
 *   operational history, 7 calendar years after the Student's final exit;
 *   an open one is never deleted and keeps the Student;
 * - processing authorizations, converted admission applications (with
 *   released applicants), the Student subject's consent events and domain
 *   preferences go with the Student core record, 25 calendar years after
 *   final exit, in the same unit and never earlier;
 * - rejected/withdrawn applications and every Guardian-subject row stay
 *   (E21.3C).
 *
 * The 25-year cases run with the application clock in the PAST relative to
 * the real clock: the database floor measures PostgreSQL's real now().
 */
class StudentLinkedResidualRetentionTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();
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

    private function in(School $school, callable $callback): mixed
    {
        return app(TenantContext::class)->withSchool($school, $callback);
    }

    private function prune(array $options = []): PendingCommand
    {
        return $this->artisan('platform:student-retention-prune', $options);
    }

    private function rows(School $school, string $table, string $column, string $id): int
    {
        return $this->in($school, fn () => DB::table($table)->where($column, $id)->count());
    }

    /** @return array<string, mixed> a School with a 2026-27 and a 2000-01 Section, Library, Transport and Hostel inventory */
    private function world(): array
    {
        $school = $this->createSchool();
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $year = $this->createAcademicYear($school, ['starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'active', 'code' => 'AY26']);
        $old = $this->createAcademicYear($school, ['starts_on' => '2000-04-01', 'ends_on' => '2001-03-31', 'status' => 'closed', 'code' => 'AY00']);
        $next = $this->createAcademicYear($school, ['starts_on' => '2027-04-01', 'ends_on' => '2028-03-31', 'status' => 'draft', 'code' => 'AY27']);
        $route = $this->createTransportRoute($school);
        $room = $this->createHostelRoom($this->createHostel($school, $campus));

        return [
            'school' => $school, 'campus' => $campus, 'grade' => $grade, 'year' => $year, 'old' => $old,
            'section' => $this->createSection($year, $campus, $grade, ['code' => 'A']),
            'oldSection' => $this->createSection($old, $campus, $grade, ['code' => 'O']),
            'nextSection' => $this->createSection($next, $campus, $grade, ['code' => 'N']),
            'title' => $this->createLibraryTitle($school), 'route' => $route, 'room' => $room,
        ];
    }

    /** An inactive Student whose only placement ended (withdrawn) on $endsOn. */
    private function leaver(array $w, string $endsOn = '2026-09-30', string $studentStatus = 'inactive'): Student
    {
        $student = $this->createStudent($w['school'], ['status' => $studentStatus]);
        $section = $endsOn < '2026-01-01' ? $w['oldSection'] : $w['section'];
        $this->createStudentEnrollment($student, $section, ['status' => 'withdrawn', 'starts_on' => $endsOn < '2026-01-01' ? '2000-06-01' : '2026-06-01', 'ends_on' => $endsOn]);

        return $student;
    }

    /** One returned loan, one ended Transport assignment and one ended Hostel residency; optionally open ones too. */
    private function moduleHistory(array $w, Student $student, bool $open = false): void
    {
        $copy = $this->createLibraryCopy($w['title']);
        $this->createLibraryLoan($copy, $student, ['status' => 'returned', 'checked_out_at' => '2026-07-01 09:00:00', 'due_at' => '2026-07-15 09:00:00', 'checked_in_at' => '2026-07-10 09:00:00']);
        $this->createTransportStudentAssignment($student, $w['route'], ['status' => 'ended', 'starts_on' => '2026-06-01 00:00:00', 'ends_on' => '2026-09-30 00:00:00']);
        $this->createHostelResidencyAssignment($student, $this->createHostelBed($w['room']), ['status' => 'ended', 'starts_on' => '2026-06-01 00:00:00', 'ends_on' => '2026-09-30 00:00:00']);

        if ($open) {
            $this->createLibraryLoan($this->createLibraryCopy($w['title']), $student, ['status' => 'active', 'checked_out_at' => '2026-09-01 09:00:00', 'due_at' => '2026-09-15 09:00:00', 'checked_in_at' => null]);
            $this->createTransportStudentAssignment($student, $w['route'], ['status' => 'active', 'starts_on' => '2026-09-01 00:00:00', 'ends_on' => null]);
            $this->createHostelResidencyAssignment($student, $this->createHostelBed($w['room']), ['status' => 'active', 'starts_on' => '2026-09-01 00:00:00', 'ends_on' => null]);
        }
    }

    /** @return array{0: int, 1: int, 2: int} loans, Transport assignments, Hostel residencies of the Student */
    private function modules(School $school, Student $student): array
    {
        return [
            $this->rows($school, 'library_loans', 'student_id', $student->id),
            $this->rows($school, 'transport_student_assignments', 'student_id', $student->id),
            $this->rows($school, 'hostel_residency_assignments', 'student_id', $student->id),
        ];
    }

    private function relationship(Student $student): string
    {
        $relationship = $this->createStudentGuardianRelationship($student, $this->createGuardian($student->school));

        return $relationship->id;
    }

    /** Inserts one processing-authorization row (append-only; INSERT is allowed). */
    private function authorization(Student $student, string $basis, string $status = 'recorded', ?string $relationshipId = null, ?string $terminates = null): string
    {
        $id = (string) Str::uuid7();
        $this->in($student->school, fn () => DB::table('student_processing_authorizations')->insert([
            'id' => $id, 'school_id' => $student->school_id, 'student_id' => $student->id, 'purpose' => 'academic_records',
            'basis_type' => $basis, 'status' => $status, 'terminates_authorization_id' => $terminates, 'student_guardian_relationship_id' => $relationshipId,
            'recorded_at' => '2000-06-01 00:00:00', 'recorded_by_user_id' => $this->createUser()->id, 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    /** A consent event and a domain preference for a Student or Guardian subject. */
    private function consent(School $school, string $column, string $subjectId): void
    {
        $this->in($school, function () use ($school, $column, $subjectId): void {
            foreach (['granted', 'withdrawn'] as $status) {
                DB::table('communication_domain_consent_events')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $school->id, $column => $subjectId, 'channel' => 'email', 'status' => $status,
                    'recorded_at' => '2000-07-01 00:00:00', 'recorded_by_user_id' => $this->createUser()->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('communication_domain_preferences')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $school->id, $column => $subjectId, 'channel' => 'email', 'preference' => 'disabled', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    /** The converted application that created $student, for the given applicant. */
    private function converted(array $w, Student $student, string $applicantId): string
    {
        $enrollmentId = $this->in($w['school'], fn () => DB::table('student_enrollments')->where('student_id', $student->id)->value('id'));
        $id = (string) Str::uuid7();
        $this->in($w['school'], fn () => DB::table('admission_applications')->insert([
            'id' => $id, 'school_id' => $w['school']->id, 'applicant_id' => $applicantId, 'academic_year_id' => $w['old']->id, 'campus_id' => $w['campus']->id,
            'grade_level_id' => $w['grade']->id, 'status' => 'converted', 'converted_student_id' => $student->id, 'converted_student_enrollment_id' => $enrollmentId,
            'converted_at' => '2000-05-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
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

    #[Test]
    public function module_history_expires_seven_years_after_final_exit_but_open_rows_stay_and_keep_the_student(): void
    {
        $w = $this->world();
        $left = $this->leaver($w);
        $this->moduleHistory($w, $left);
        $stillHolding = $this->leaver($w);
        $this->moduleHistory($w, $stillHolding, open: true);
        $current = $this->createStudent($w['school'], ['status' => 'active']);
        $this->createStudentEnrollment($current, $w['section'], ['status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => null]);
        $this->moduleHistory($w, $current);

        // Exactly seven years after the exit day: kept (strict boundary).
        $this->at('2033-09-30');
        $this->prune(['--only' => 'operational'])->expectsOutputToContain('returned Library loans of 0 Student(s), ended Transport assignments of 0, ended Hostel residencies of 0')->assertSuccessful();
        $this->assertSame([1, 1, 1], $this->modules($w['school'], $left));

        $this->at('2033-10-01');
        $this->prune(['--only' => 'operational', '--dry-run' => true])->expectsOutputToContain('Dry run: would delete operational module history: returned Library loans of 2 Student(s), ended Transport assignments of 2, ended Hostel residencies of 2 (dependency-blocked: 0, held: 0, errors: 0)')->assertSuccessful();
        $this->assertSame([1, 1, 1], $this->modules($w['school'], $left), 'a dry run deletes nothing');

        $this->prune(['--only' => 'operational'])->expectsOutputToContain('Deleted operational module history: returned Library loans of 2 Student(s), ended Transport assignments of 2, ended Hostel residencies of 2 (dependency-blocked: 0, held: 0, errors: 0)')->assertSuccessful();
        $this->assertSame([0, 0, 0], $this->modules($w['school'], $left));
        $this->assertSame([1, 1, 1], $this->modules($w['school'], $stillHolding), 'the open loan, assignment and residency stay');
        $this->assertSame([1, 1, 1], $this->modules($w['school'], $current), 'a current Student keeps its history');
        $this->in($w['school'], function () use ($w): void {
            $this->assertSame(1, DB::table('library_titles')->count(), 'Library inventory stays');
            $this->assertSame(1, DB::table('transport_routes')->where('id', $w['route']->id)->count(), 'Transport configuration stays');
            $this->assertSame(4, DB::table('hostel_beds')->count(), 'Hostel configuration stays');
        });

        // Rerun: nothing left to delete.
        $this->prune(['--only' => 'operational'])->expectsOutputToContain('returned Library loans of 0 Student(s), ended Transport assignments of 0, ended Hostel residencies of 0')->assertSuccessful();

        // The core record: the open rows keep their Student; the dry run agrees with the run.
        $this->at('2051-10-01');
        $this->prune(['--dry-run' => true])->expectsOutputToContain('Dry run: would delete the core record of 1 Student(s) (unresolved exit: 0, dependency-blocked: 1')->assertSuccessful();
        $this->prune()->expectsOutputToContain('Deleted the core record of 1 Student(s) (unresolved exit: 0, dependency-blocked: 1')->assertSuccessful();
        $this->assertSame(0, $this->rows($w['school'], 'students', 'id', $left->id));
        $this->assertSame(1, $this->rows($w['school'], 'students', 'id', $stillHolding->id));
        $this->assertSame([1, 1, 1], $this->modules($w['school'], $stillHolding));
    }

    #[Test]
    public function re_entry_keeps_module_history_and_restarts_its_clock(): void
    {
        $w = $this->world();
        $reEnrolled = $this->leaver($w);
        $this->moduleHistory($w, $reEnrolled);
        $this->createStudentEnrollment($reEnrolled, $w['nextSection'], ['status' => 'active', 'starts_on' => '2027-04-01', 'ends_on' => null]);
        $reactivated = $this->leaver($w, studentStatus: 'active');
        $this->moduleHistory($w, $reactivated);
        $returnedAndLeft = $this->leaver($w);
        $this->moduleHistory($w, $returnedAndLeft);
        $this->createStudentEnrollment($returnedAndLeft, $w['nextSection'], ['status' => 'completed', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31']);

        $this->at('2033-10-01');
        $this->prune(['--only' => 'operational'])->expectsOutputToContain('returned Library loans of 0 Student(s), ended Transport assignments of 0, ended Hostel residencies of 0')->assertSuccessful();

        // The returning Student's clock started at its LAST departure (2028-03-31).
        $this->at('2035-04-01');
        $this->prune(['--only' => 'operational'])->expectsOutputToContain('returned Library loans of 1 Student(s), ended Transport assignments of 1, ended Hostel residencies of 1')->assertSuccessful();
        $this->assertSame([0, 0, 0], $this->modules($w['school'], $returnedAndLeft));
        $this->assertSame([1, 1, 1], $this->modules($w['school'], $reEnrolled));
        $this->assertSame([1, 1, 1], $this->modules($w['school'], $reactivated));
    }

    #[Test]
    public function a_held_school_keeps_module_history_and_another_school_is_unaffected_by_it(): void
    {
        $held = $this->world();
        $other = $this->world();
        config(['retention.hold_school_ids' => [$held['school']->id]]);
        $heldStudent = $this->leaver($held);
        $this->moduleHistory($held, $heldStudent);
        $otherStudent = $this->leaver($other);
        $this->moduleHistory($other, $otherStudent);

        $this->at('2033-10-01');
        $this->prune(['--only' => 'operational'])->expectsOutputToContain('returned Library loans of 1 Student(s), ended Transport assignments of 1, ended Hostel residencies of 1 (dependency-blocked: 0, held: 3, errors: 0)')->assertSuccessful();

        $this->assertSame([1, 1, 1], $this->modules($held['school'], $heldStudent));
        $this->assertSame([0, 0, 0], $this->modules($other['school'], $otherStudent));
        // School A's context never sees School B's rows (RLS).
        $this->assertSame(0, $this->in($held['school'], fn () => DB::table('library_loans')->where('student_id', $otherStudent->id)->count()));
    }

    #[Test]
    public function core_evidence_goes_with_the_core_record_exactly_25_years_after_exit_and_never_earlier(): void
    {
        $w = $this->world();

        // S1: guardian consent (withdrawn) then a statutory basis; consent and preference; a converted application whose applicant has nothing else.
        $s1 = $this->leaver($w, '2001-02-14');
        $relationshipId = $this->relationship($s1);
        $grant = $this->authorization($s1, 'guardian_consent', relationshipId: $relationshipId);
        $this->authorization($s1, 'guardian_consent', 'withdrawn', $relationshipId, $grant);
        $this->authorization($s1, 'statutory_school_purpose');
        $this->consent($w['school'], 'student_id', $s1->id);
        $released = $this->createApplicant($w['school']);
        $this->converted($w, $s1, $released->id);
        $guardianId = $this->in($w['school'], fn () => DB::table('student_guardian_relationships')->where('id', $relationshipId)->value('guardian_id'));
        $this->consent($w['school'], 'guardian_id', $guardianId);

        // S2: converted, but its applicant also has a rejected application (E21.3C): the applicant and that application stay.
        $s2 = $this->leaver($w, '2001-02-14');
        $shared = $this->createApplicant($w['school']);
        $this->converted($w, $s2, $shared->id);
        $rejected = $this->createAdmissionApplication($shared, $w['year'], $w['campus'], $w['grade'], ['status' => 'rejected']);

        // Never eligible: a current Student with an authorization, and one who left recently.
        $current = $this->createStudent($w['school'], ['status' => 'active']);
        $this->createStudentEnrollment($current, $w['oldSection'], ['status' => 'active', 'starts_on' => '2000-06-01', 'ends_on' => null]);
        $this->authorization($current, 'statutory_school_purpose');
        $recent = $this->leaver($w, '2020-03-31');
        $this->authorization($recent, 'statutory_school_purpose');
        $this->consent($w['school'], 'student_id', $recent->id);

        $kept = function () use ($w, $s1, $s2, $relationshipId, $released): void {
            $this->assertSame(3, $this->rows($w['school'], 'student_processing_authorizations', 'student_id', $s1->id));
            $this->assertSame(1, $this->rows($w['school'], 'student_guardian_relationships', 'id', $relationshipId));
            $this->assertSame(2, $this->rows($w['school'], 'communication_domain_consent_events', 'student_id', $s1->id));
            $this->assertSame(1, $this->rows($w['school'], 'communication_domain_preferences', 'student_id', $s1->id));
            $this->assertSame(1, $this->rows($w['school'], 'admission_applications', 'converted_student_id', $s1->id));
            $this->assertSame(1, $this->rows($w['school'], 'applicants', 'id', $released->id));
            $this->assertSame(1, $this->rows($w['school'], 'students', 'id', $s2->id));
        };

        // The operational phase (long past 7 y) leaves the relationship an authorization names.
        $this->at('2026-02-14');
        $this->prune()->expectsOutputToContain('Guardian relationships of 0')->expectsOutputToContain('core record of 0 Student(s)')->assertSuccessful();
        $kept();

        $this->at('2026-02-15');
        $this->prune(['--dry-run' => true])->expectsOutputToContain('Dry run: would delete the core record of 2 Student(s) (unresolved exit: 0, dependency-blocked: 0, held: 0, errors: 0)')->assertSuccessful();
        $kept();

        $this->prune()->expectsOutputToContain('Deleted the core record of 2 Student(s) (unresolved exit: 0, dependency-blocked: 0, held: 0, errors: 0)')->assertSuccessful();

        foreach ([$s1, $s2] as $student) {
            $this->assertSame(0, $this->rows($w['school'], 'students', 'id', $student->id));
            $this->assertSame(0, $this->rows($w['school'], 'student_processing_authorizations', 'student_id', $student->id));
            $this->assertSame(0, $this->rows($w['school'], 'admission_applications', 'converted_student_id', $student->id));
            $this->assertSame(0, $this->rows($w['school'], 'communication_domain_consent_events', 'student_id', $student->id));
            $this->assertSame(0, $this->rows($w['school'], 'communication_domain_preferences', 'student_id', $student->id));
        }
        $this->assertSame(0, $this->rows($w['school'], 'student_guardian_relationships', 'id', $relationshipId), 'the relationship goes with the authorization naming it');
        $this->assertSame(0, $this->rows($w['school'], 'applicants', 'id', $released->id), 'an applicant with nothing else leaves with its converted application');
        $this->assertSame(1, $this->rows($w['school'], 'applicants', 'id', $shared->id), 'an applicant with another application stays');
        $this->assertSame(1, $this->rows($w['school'], 'admission_applications', 'id', $rejected->id), 'rejected/withdrawn applications wait for E21.3C');
        // Guardian personal data, and the Guardian subject's consent and preference, stay (E21.3C).
        $this->assertSame(1, $this->rows($w['school'], 'guardians', 'id', $guardianId));
        $this->assertSame(2, $this->rows($w['school'], 'communication_domain_consent_events', 'guardian_id', $guardianId));
        $this->assertSame(1, $this->rows($w['school'], 'communication_domain_preferences', 'guardian_id', $guardianId));
        // Never eligible.
        $this->assertSame(1, $this->rows($w['school'], 'student_processing_authorizations', 'student_id', $current->id), 'an active authorization of a current Student');
        $this->assertSame(1, $this->rows($w['school'], 'student_processing_authorizations', 'student_id', $recent->id), 'the core period is still running');
        $this->assertSame(2, $this->rows($w['school'], 'communication_domain_consent_events', 'student_id', $recent->id));
    }

    #[Test]
    public function a_held_school_keeps_its_core_evidence_and_another_school_is_unaffected(): void
    {
        $held = $this->world();
        $other = $this->world();
        config(['retention.hold_school_ids' => [$held['school']->id]]);
        $heldStudent = $this->leaver($held, '2001-02-14');
        $this->authorization($heldStudent, 'statutory_school_purpose');
        $this->consent($held['school'], 'student_id', $heldStudent->id);
        $otherStudent = $this->leaver($other, '2001-02-14');
        $this->authorization($otherStudent, 'statutory_school_purpose');
        $this->consent($other['school'], 'student_id', $otherStudent->id);

        $this->at('2026-06-15');
        $this->prune(['--only' => 'core'])->expectsOutputToContain('core record of 1 Student(s) (unresolved exit: 0, dependency-blocked: 0, held: 1, errors: 0)')->assertSuccessful();

        $this->assertSame(1, $this->rows($held['school'], 'student_processing_authorizations', 'student_id', $heldStudent->id));
        $this->assertSame(2, $this->rows($held['school'], 'communication_domain_consent_events', 'student_id', $heldStudent->id));
        $this->assertSame(0, $this->rows($other['school'], 'student_processing_authorizations', 'student_id', $otherStudent->id));
        $this->assertSame(0, $this->rows($other['school'], 'students', 'id', $otherStudent->id));
    }

    #[Test]
    public function an_application_converted_into_another_student_naming_this_placement_keeps_the_student(): void
    {
        $w = $this->world();
        $student = $this->leaver($w, '2001-02-14');
        $other = $this->leaver($w, '2001-02-14');
        $enrollmentId = $this->in($w['school'], fn () => DB::table('student_enrollments')->where('student_id', $student->id)->value('id'));
        $this->in($w['school'], fn () => DB::table('admission_applications')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'applicant_id' => $this->createApplicant($w['school'])->id, 'academic_year_id' => $w['old']->id,
            'campus_id' => $w['campus']->id, 'grade_level_id' => $w['grade']->id, 'status' => 'converted', 'converted_student_id' => $other->id,
            'converted_student_enrollment_id' => $enrollmentId, 'converted_at' => '2000-05-01 00:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]));
        // The other Student is kept by an unreturned loan, so the outcome does not depend on the walk order.
        $this->createLibraryLoan($this->createLibraryCopy($w['title']), $other, ['status' => 'active', 'checked_out_at' => '2000-09-01 09:00:00', 'due_at' => '2000-09-15 09:00:00', 'checked_in_at' => null]);

        $this->at('2026-06-15');
        $this->prune(['--only' => 'core'])->expectsOutputToContain('core record of 0 Student(s) (unresolved exit: 0, dependency-blocked: 2')->assertSuccessful();
        $this->assertSame(1, $this->rows($w['school'], 'students', 'id', $student->id));
        $this->assertSame(1, $this->rows($w['school'], 'students', 'id', $other->id));
    }

    #[Test]
    public function the_core_evidence_paths_refuse_direct_deletes_young_records_and_other_schools(): void
    {
        $w = $this->world();
        $b = $this->createSchool();
        $old = $this->leaver($w, '2001-02-14');
        $this->authorization($old, 'statutory_school_purpose');
        $this->consent($w['school'], 'student_id', $old->id);
        $recent = $this->leaver($w, '2020-03-31');
        $active = $this->createStudent($w['school'], ['status' => 'active']);
        $this->createStudentEnrollment($active, $w['oldSection'], ['status' => 'withdrawn', 'starts_on' => '2000-06-01', 'ends_on' => '2001-02-14']);
        $expiry = app(RetentionExpiry::class);
        $oldCutoff = '2001-03-01';

        $this->in($w['school'], function () use ($w, $old, $recent, $active, $expiry, $oldCutoff): void {
            // The runtime role: no direct delete, not even with the flag set.
            $this->refused(fn () => DB::table('student_processing_authorizations')->where('student_id', $old->id)->delete(), 'append-only');
            $this->refused(function () use ($old): void {
                DB::select("select set_config('app.student_core_retention', 'on', true)");
                DB::table('student_processing_authorizations')->where('student_id', $old->id)->delete();
            }, 'append-only');
            $this->refused(fn () => DB::table('communication_domain_consent_events')->where('student_id', $old->id)->delete(), 'permission denied');

            // The database floor: a young cutoff, a recent exit, a current Student.
            $young = now('UTC')->subYears(25)->addDays(3)->toDateString();
            $this->refused(fn () => $expiry->studentCoreEvidence(RetentionExpiry::STUDENT_PROCESSING_AUTHORIZATION, $w['school'], $old->id, $young, true), 'retention_floor');
            $this->refused(fn () => $expiry->studentCoreEvidence(RetentionExpiry::STUDENT_CONSENT_EVENT, $w['school'], $recent->id, $oldCutoff, true), 'retention_student_core');
            $this->refused(fn () => $expiry->studentCoreEvidence(RetentionExpiry::STUDENT_PROCESSING_AUTHORIZATION, $w['school'], $active->id, $oldCutoff, true), 'retention_student_core');

            // An eligible Student: the dry run counts, nothing is removed.
            $this->assertSame(1, DB::transaction(fn () => $expiry->studentCoreEvidence(RetentionExpiry::STUDENT_PROCESSING_AUTHORIZATION, $w['school'], $old->id, $oldCutoff, true)));
            $this->assertSame(2, DB::transaction(fn () => $expiry->studentCoreEvidence(RetentionExpiry::STUDENT_CONSENT_EVENT, $w['school'], $old->id, $oldCutoff, true)));
            $this->assertSame(1, DB::table('student_processing_authorizations')->where('student_id', $old->id)->count());
        });

        // Another School's context, or none: refused before anything runs.
        $this->in($b, fn () => $this->refused(fn () => $expiry->studentCoreEvidence(RetentionExpiry::STUDENT_PROCESSING_AUTHORIZATION, $w['school'], $old->id, $oldCutoff, false), 'retention_tenant'));
        $this->refused(fn () => $expiry->studentCoreEvidence(RetentionExpiry::STUDENT_CONSENT_EVENT, $w['school'], $old->id, $oldCutoff, false), 'retention_tenant');
        $this->refused(fn () => DB::select('select retention_assert_student_core_floor(?, ?, ?)', [$w['school']->id, $old->id, $oldCutoff]), 'permission denied');

        $this->assertSame(1, $this->rows($w['school'], 'student_processing_authorizations', 'student_id', $old->id));
        $check = collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');
        $this->assertSame(CheckResult::PASS, $check['retention_functions_narrow']->status);
        $this->assertSame(CheckResult::PASS, $check['runtime_destructive_privileges_restricted']->status);
    }
}
