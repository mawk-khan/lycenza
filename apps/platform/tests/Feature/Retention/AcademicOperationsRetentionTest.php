<?php

namespace Tests\Feature\Retention;

use App\Domain\AcademicStructure\Application\Retention\AcademicYearRetention;
use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Attendance\Application\Retention\AttendanceSessionRetentionService;
use App\Domain\CurriculumDelivery\Application\Retention\CurriculumDeliveryRetentionService;
use App\Domain\Documents\Infrastructure\Document;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\Timetable\Application\Retention\TimetableEntryRetentionService;
use App\Models\School;
use App\Support\Operations\CheckResult;
use App\Support\Operations\DatabaseRoleVerifier;
use App\Support\Retention\LmsResourceRetention;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * E21.3D (E21.2G A1, project-adopted, pending legal ratification):
 * curriculum deliveries, attendance register headers (once empty),
 * timetable entries (once no header references them) and LMS Learning
 * Content/Assignments (with audiences and Documents, past the D6 minimum)
 * go 7 calendar years after the end of their authoritative Academic Year.
 * Syllabus and examination configuration stay (tenant lifetime).
 *
 * The LMS cases keep the application clock in the PAST relative to the
 * real clock: its database floor measures PostgreSQL's real now().
 */
class AcademicOperationsRetentionTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesAttendanceFixtures;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = (string) config('documents.disk');
        Storage::fake($this->disk);
        config(['retention.academic_operations_years' => 7, 'retention.authority_history_years' => 7, 'retention.hold_school_ids' => []]);
    }

    private function at(string $date): void
    {
        $this->travelTo(Carbon::parse($date.' 12:00:00', 'UTC'));
    }

    private function prune(array $options = []): PendingCommand
    {
        return $this->artisan('platform:academic-retention-prune', $options);
    }

    /**
     * One School's run through the same services, in the command's order, for
     * EXACT per-School counts (the command walks every School in the database,
     * committed fixtures of other test classes included).
     *
     * @return array<string, array<string, int>> category => counts
     */
    private function schoolRun(School $school, bool $dryRun = false, bool $held = false): array
    {
        $cutoff = AcademicYearRetention::cutoff($school, (int) config('retention.academic_operations_years'));

        return [
            'delivery' => app(CurriculumDeliveryRetentionService::class)->prune($school, $cutoff, 500, $dryRun, $held),
            'header' => app(AttendanceSessionRetentionService::class)->prune($school, $cutoff, 500, $dryRun, $held),
            'entry' => app(TimetableEntryRetentionService::class)->prune($school, $cutoff, 500, $dryRun, $held),
            'content' => app(LmsResourceRetention::class)->prune('learning_content', $school, $cutoff, 500, $dryRun, $held),
            'assignment' => app(LmsResourceRetention::class)->prune('assignment', $school, $cutoff, 500, $dryRun, $held),
        ];
    }

    /** @param  array<string, array<string, int>>  $expected  category => the outcomes to pin */
    private function assertRun(array $expected, array $actual): void
    {
        foreach ($expected as $category => $outcomes) {
            foreach ($outcomes as $outcome => $count) {
                $this->assertSame($count, $actual[$category][$outcome], "{$category}.{$outcome}");
            }
        }
    }

    private function rows(School $school, string $table, string $id): int
    {
        return $this->inSchool($school, fn () => DB::table($table)->where('id', $id)->count());
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

    /** @return array<string, mixed> one School, one Academic Year ending on $endsOn, a Section, Offering, teacher and timetable entry */
    private function year(?School $school, string $startsOn, string $endsOn, string $code): array
    {
        $school ??= $this->createSchool();
        $campus = $this->createCampus($school);
        $year = $this->createAcademicYear($school, ['status' => 'closed', 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'code' => $code]);
        $grade = $this->createGradeLevel($school);
        $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($school), ['is_required' => true, 'status' => 'active']);
        $section = $this->createSection($year, $campus, $grade, ['status' => 'active', 'code' => 'S'.Str::random(4)]);
        $teacher = $this->createEmployee($school, ['record_status' => 'active']);
        $period = $this->createTimetablePeriod($school, ['start_time' => '09:00:00', 'end_time' => '10:00:00']);
        $entry = $this->createTimetableEntry($offering, $section, $teacher, $period, ['day_of_week' => 1]);

        return compact('school', 'campus', 'year', 'grade', 'offering', 'section', 'teacher', 'period', 'entry');
    }

    private function delivery(array $w): string
    {
        $unit = (string) Str::uuid7();
        $id = (string) Str::uuid7();
        $this->inSchool($w['school'], function () use ($w, $unit, $id): void {
            DB::table('syllabus_units')->insert(['id' => $unit, 'school_id' => $w['school']->id, 'subject_offering_id' => $w['offering']->id, 'code' => 'U'.Str::random(4), 'title' => 'Unit', 'sequence' => 1, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('curriculum_deliveries')->insert([
                'id' => $id, 'school_id' => $w['school']->id, 'section_id' => $w['section']->id, 'syllabus_unit_id' => $unit, 'subject_offering_id' => $w['offering']->id,
                'academic_year_id' => $w['year']->id, 'campus_id' => $w['campus']->id, 'grade_level_id' => $w['grade']->id, 'started_on' => $w['year']->starts_on,
                'completed_on' => null, 'status' => 'in_progress', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return $id;
    }

    private int $day = 0;

    private function registerHeader(array $w): string
    {
        $id = (string) Str::uuid7();
        $date = Carbon::parse($w['year']->starts_on)->addDays($this->day++)->toDateString();
        $this->inSchool($w['school'], fn () => DB::table('attendance_sessions')->insert([
            'id' => $id, 'school_id' => $w['school']->id, 'timetable_entry_id' => $w['entry']->id, 'attendance_date' => $date,
            'academic_year_id' => $w['year']->id, 'campus_id' => $w['campus']->id, 'grade_level_id' => $w['grade']->id, 'section_id' => $w['section']->id,
            'subject_offering_id' => $w['offering']->id, 'teacher_id' => $w['teacher']->id, 'period_id' => $w['period']->id, 'period_start_time' => '09:00:00',
            'period_end_time' => '10:00:00', 'submitted_by_user_id' => $this->createUser()->id, 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    private function record(array $w, string $sessionId): void
    {
        $student = $this->createStudent($w['school']);
        $enrollment = $this->createStudentEnrollment($student, $w['section'], ['status' => 'active', 'starts_on' => $w['year']->starts_on, 'ends_on' => null]);
        $this->inSchool($w['school'], fn () => DB::table('attendance_records')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'attendance_session_id' => $sessionId, 'student_enrollment_id' => $enrollment->id,
            'academic_year_id' => $w['year']->id, 'campus_id' => $w['campus']->id, 'grade_level_id' => $w['grade']->id, 'section_id' => $w['section']->id,
            'status' => 'present', 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    /** An LMS resource; owned ones get an audience over the year's Section and a TeachingAssignment ending on $taEndsOn. */
    private function lms(array $w, string $kind, ?Employee $owner = null, ?string $taEndsOn = null): string
    {
        [$table, $bridge, $fk] = ['learning_content' => ['learning_content', 'learning_content_section_audiences', 'learning_content_id'], 'assignment' => ['assignments', 'assignment_section_audiences', 'assignment_id']][$kind];
        $id = (string) Str::uuid7();
        // One transaction: an owned resource needs its audience at commit (deferred check; fixtures are committed).
        $this->inSchool($w['school'], fn () => DB::transaction(function () use ($w, $kind, $table, $bridge, $fk, $id, $owner, $taEndsOn): void {
            DB::table($table)->insert(array_merge([
                'id' => $id, 'school_id' => $w['school']->id, 'subject_offering_id' => $w['offering']->id, 'title' => 'Material', 'status' => 'published',
                'owner_employee_id' => $owner?->id, 'created_at' => now(), 'updated_at' => now(),
            ], $kind === 'learning_content' ? ['sequence' => 1] : []));
            if ($owner !== null) {
                DB::table($bridge)->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, $fk => $id, 'subject_offering_id' => $w['offering']->id,
                    'academic_year_id' => $w['year']->id, 'campus_id' => $w['campus']->id, 'grade_level_id' => $w['grade']->id, 'section_id' => $w['section']->id, 'created_at' => now(),
                ]);
                DB::table('teaching_assignments')->insert([
                    'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'employee_id' => $owner->id, 'academic_year_id' => $w['year']->id,
                    'campus_id' => $w['campus']->id, 'grade_level_id' => $w['grade']->id, 'section_id' => $w['section']->id, 'subject_offering_id' => $w['offering']->id,
                    'starts_on' => $w['year']->starts_on, 'ends_on' => $taEndsOn, 'created_by_user_id' => $this->createUser()->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }));

        return $id;
    }

    private function lmsDocument(array $w, string $kind, string $parentId): string
    {
        $path = "schools/{$w['school']->id}/documents/{$kind}/{$parentId}/".Str::uuid7().'.pdf';
        Storage::disk($this->disk)->put($path, 'bytes');
        $this->inSchool($w['school'], fn () => Document::query()->forceCreate([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, ($kind === 'learning_content' ? 'learning_content_id' : 'assignment_id') => $parentId,
            'classification_tier' => 'internal', 'storage_disk' => $this->disk, 'storage_path' => $path, 'original_filename' => 'm.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 5, 'uploaded_at' => now(), 'status' => 'active',
        ]));

        return $path;
    }

    #[Test]
    public function deliveries_headers_and_entries_go_seven_calendar_years_after_their_year_ended_in_dependency_order(): void
    {
        $old = $this->year(null, '2026-04-01', '2027-03-31', 'AY26');
        $young = $this->year($old['school'], '2027-04-01', '2028-03-31', 'AY27');
        $oldDelivery = $this->delivery($old);
        $youngDelivery = $this->delivery($young);
        $empty = $this->registerHeader($old);
        $busy = $this->registerHeader($old);
        $this->record($old, $busy);
        $youngSession = $this->registerHeader($young);
        $audit = $this->inSchool($old['school'], fn () => DB::table('school_audit_events')->count());

        // Exactly seven years after the year's last day: kept (strict boundary).
        $this->at('2034-03-31');
        $this->assertRun(['delivery' => ['eligible' => 0], 'header' => ['eligible' => 0], 'entry' => ['eligible' => 0]], $this->schoolRun($old['school']));

        $this->at('2034-04-01');
        $this->assertRun([
            'delivery' => ['eligible' => 1, 'deleted' => 0, 'dependency_blocked' => 0],
            'header' => ['eligible' => 2, 'deleted' => 0, 'dependency_blocked' => 1],
            'entry' => ['eligible' => 1, 'deleted' => 0, 'dependency_blocked' => 1],
        ], $this->schoolRun($old['school'], dryRun: true));
        $this->assertSame(1, $this->rows($old['school'], 'curriculum_deliveries', $oldDelivery), 'a dry run deletes nothing');

        // The command itself: the same services for every School.
        $this->prune()->expectsOutputToContain('curriculum deliveries')->expectsOutputToContain('attendance register headers')->assertSuccessful();

        $this->assertSame(0, $this->rows($old['school'], 'curriculum_deliveries', $oldDelivery));
        $this->assertSame(1, $this->rows($old['school'], 'curriculum_deliveries', $youngDelivery), 'a younger year keeps its deliveries');
        $this->assertSame(0, $this->rows($old['school'], 'attendance_sessions', $empty));
        $this->assertSame(1, $this->rows($old['school'], 'attendance_sessions', $busy), 'a header with a Student record left stays');
        $this->assertSame(1, $this->rows($old['school'], 'attendance_sessions', $youngSession));
        $this->assertSame(1, $this->rows($old['school'], 'timetable_entries', $old['entry']->id), 'the entry stays while a header references it');
        $this->assertSame($audit, $this->inSchool($old['school'], fn () => DB::table('school_audit_events')->count()), 'the audit ledger is untouched');
        $this->inSchool($old['school'], function () use ($old): void {
            $this->assertSame(2, DB::table('syllabus_units')->count(), 'syllabus units are tenant lifetime');
            $this->assertSame(1, DB::table('academic_years')->where('id', $old['year']->id)->count(), 'the year itself stays');
            $this->assertSame(2, DB::table('timetable_periods')->count(), 'periods are School configuration');
        });

        // Once the Student record is gone (its own D7 clock), the header and then the entry follow.
        // (Simulated through the schema owner: since E21-RH.6 only the retention identity deletes attendance records.)
        DB::connection('pgsql_admin')->transaction(function () use ($old, $busy): void {
            DB::connection('pgsql_admin')->select("select set_config('app.current_school_id', ?, true)", [$old['school']->id]);
            DB::connection('pgsql_admin')->table('attendance_records')->where('attendance_session_id', $busy)->delete();
        });
        $this->assertRun(['header' => ['deleted' => 1, 'dependency_blocked' => 0], 'entry' => ['deleted' => 1, 'dependency_blocked' => 0]], $this->schoolRun($old['school']));
        $this->assertSame(0, $this->rows($old['school'], 'timetable_entries', $old['entry']->id));
        $this->assertSame(1, $this->rows($old['school'], 'employees', $old['teacher']->id), 'the teacher is never deleted here; only its references are released');

        $this->assertRun(['delivery' => ['eligible' => 0], 'header' => ['eligible' => 0], 'entry' => ['eligible' => 0]], $this->schoolRun($old['school']));
    }

    #[Test]
    public function an_expired_timetable_entry_releases_its_teacher_while_other_retained_references_still_block(): void
    {
        // E21.3D releases academic references only by the rows' own expiry; the
        // Employee then goes on its own D9 clock, never by a recursive call.
        config(['retention.employee_ancillary_years' => 2, 'retention.employee_evidence_years' => 8]);
        $w = $this->year(null, '2010-04-01', '2011-03-31', 'AY10');
        $this->createEmploymentRecord($w['teacher'], ['status' => 'separated', 'starts_on' => '2010-01-01', 'ends_on' => '2011-06-30']);
        $other = $this->createEmployee($w['school'], ['record_status' => 'active']);
        $this->createEmploymentRecord($other, ['status' => 'separated', 'starts_on' => '2010-01-01', 'ends_on' => '2011-06-30']);
        $this->createTimetableEntry($w['offering'], $w['section'], $other, $w['period'], ['day_of_week' => 2]);
        $this->inSchool($w['school'], fn () => DB::table('teaching_assignments')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'employee_id' => $other->id, 'academic_year_id' => $w['year']->id,
            'campus_id' => $w['campus']->id, 'grade_level_id' => $w['grade']->id, 'section_id' => $w['section']->id, 'subject_offering_id' => $w['offering']->id,
            'starts_on' => '2010-04-01', 'ends_on' => null, 'created_by_user_id' => $this->createUser()->id, 'created_at' => now(), 'updated_at' => now(),
        ]));

        $this->at('2040-01-01');
        $this->artisan('platform:employee-retention-prune', ['--only' => 'evidence'])->assertSuccessful();
        $this->assertSame(1, $this->rows($w['school'], 'employees', $w['teacher']->id), 'the timetable reference keeps the teacher until the entry itself goes');

        $this->assertRun(['entry' => ['deleted' => 2]], $this->schoolRun($w['school']));
        $this->artisan('platform:employee-retention-prune', ['--only' => 'evidence'])->assertSuccessful();
        $this->assertSame(0, $this->rows($w['school'], 'employees', $w['teacher']->id), 'released: the teacher goes on its own D9 clock');
        $this->assertSame(1, $this->rows($w['school'], 'employees', $other->id), 'an open TeachingAssignment (D6) still keeps its Employee');
    }

    #[Test]
    public function a_leap_day_year_end_expires_on_the_first_of_march(): void
    {
        $w = $this->year(null, '2027-03-01', '2028-02-29', 'AYL');
        $delivery = $this->delivery($w);

        $this->at('2035-02-28');
        $this->assertRun(['delivery' => ['eligible' => 0]], $this->schoolRun($w['school']));
        $this->at('2035-03-01');
        $this->assertRun(['delivery' => ['deleted' => 1]], $this->schoolRun($w['school']));
        $this->assertSame(0, $this->rows($w['school'], 'curriculum_deliveries', $delivery));
    }

    #[Test]
    public function a_held_school_keeps_everything_and_another_school_is_unaffected(): void
    {
        $held = $this->year(null, '2026-04-01', '2027-03-31', 'AY26');
        $other = $this->year(null, '2026-04-01', '2027-03-31', 'AY26');
        config(['retention.hold_school_ids' => [$held['school']->id]]);
        // E21-RH.6: destructive retention refuses while a configured hold is unrecorded; record it (as reconcile does).
        app(RetentionHolds::class)->place($held['school']->id, 'litigation', 'TEST-HOLD');
        $heldDelivery = $this->delivery($held);
        $otherDelivery = $this->delivery($other);

        $this->at('2040-01-01');
        $this->assertRun(['delivery' => ['eligible' => 1, 'held' => 1, 'deleted' => 0], 'entry' => ['held' => 1, 'deleted' => 0]], $this->schoolRun($held['school'], held: true));
        // The command honours the configured hold for every School.
        $this->prune()->assertSuccessful();

        $this->assertSame(1, $this->rows($held['school'], 'curriculum_deliveries', $heldDelivery));
        $this->assertSame(1, $this->rows($held['school'], 'timetable_entries', $held['entry']->id));
        $this->assertSame(0, $this->rows($other['school'], 'curriculum_deliveries', $otherDelivery));
        $this->assertSame(0, $this->inSchool($held['school'], fn () => DB::table('curriculum_deliveries')->where('id', $otherDelivery)->count()), 'RLS: School A never sees School B');
    }

    #[Test]
    public function the_academic_year_dates_are_immutable(): void
    {
        $w = $this->year(null, '2026-04-01', '2027-03-31', 'AY26');

        $this->inSchool($w['school'], function () use ($w): void {
            $this->refused(fn () => DB::table('academic_years')->where('id', $w['year']->id)->update(['ends_on' => '2040-03-31']), 'the date range is fixed at creation');
            $this->refused(fn () => DB::table('academic_years')->where('id', $w['year']->id)->update(['starts_on' => '2020-04-01']), 'the date range is fixed at creation');
            $this->assertSame(1, DB::table('academic_years')->where('id', $w['year']->id)->update(['name' => 'Renamed']), 'name and code stay editable');
        });
        $this->assertSame('2027-03-31', $this->inSchool($w['school'], fn () => AcademicYear::query()->findOrFail($w['year']->id))->ends_on->toDateString());
    }

    #[Test]
    public function lms_resources_go_with_their_documents_audiences_after_both_the_year_and_the_d6_minimum(): void
    {
        $w = $this->year(null, '2017-04-01', '2018-03-31', 'AY17');
        $owner = $this->createEmployee($w['school'], ['record_status' => 'active']);
        $unowned = $this->lms($w, 'learning_content');
        $unownedPath = $this->lmsDocument($w, 'learning_content', $unowned);
        $owned = $this->lms($w, 'assignment', $owner, '2018-03-31');
        $ownedPath = $this->lmsDocument($w, 'assignment', $owned);
        $lateAuthority = $this->lms($w, 'learning_content', $this->createEmployee($w['school'], ['record_status' => 'active']), '2018-03-31');
        $openAuthority = $this->lms($w, 'assignment', $this->createEmployee($w['school'], ['record_status' => 'active']), null);

        // Exactly seven years after the year's end: kept.
        $this->at('2025-03-31');
        $this->assertRun(['content' => ['eligible' => 0], 'assignment' => ['eligible' => 0]], $this->schoolRun($w['school']));

        // Without an authority period, teacher-owned resources keep their embedded owner/audience.
        $this->at('2025-04-01');
        config(['retention.authority_history_years' => null]);
        $this->assertRun(['content' => ['deleted' => 1, 'dependency_blocked' => 1], 'assignment' => ['deleted' => 0, 'dependency_blocked' => 2]], $this->schoolRun($w['school']));
        $this->assertSame(0, $this->rows($w['school'], 'learning_content', $unowned), 'Offering-wide material has no embedded authority');
        Storage::disk($this->disk)->assertMissing($unownedPath);
        $this->assertSame(1, $this->rows($w['school'], 'assignments', $owned));

        // A longer configured authority period wins over the year clock.
        config(['retention.authority_history_years' => 8]);
        $this->assertRun(['assignment' => ['deleted' => 0, 'dependency_blocked' => 2]], $this->schoolRun($w['school']));

        config(['retention.authority_history_years' => 7]);
        $this->assertRun(['content' => ['eligible' => 1, 'deleted' => 0, 'dependency_blocked' => 0], 'assignment' => ['eligible' => 2, 'deleted' => 0, 'dependency_blocked' => 1]], $this->schoolRun($w['school'], dryRun: true));
        $this->assertRun(['content' => ['deleted' => 1], 'assignment' => ['deleted' => 1, 'dependency_blocked' => 1]], $this->schoolRun($w['school']));

        $this->inSchool($w['school'], function () use ($owned, $lateAuthority, $openAuthority, $owner): void {
            $this->assertSame(0, DB::table('assignments')->where('id', $owned)->count());
            $this->assertSame(0, DB::table('assignment_section_audiences')->where('assignment_id', $owned)->count(), 'the audience leaves with its resource, never on its own');
            $this->assertSame(0, DB::table('documents')->where('assignment_id', $owned)->count());
            $this->assertSame(0, DB::table('learning_content')->where('id', $lateAuthority)->count());
            $this->assertSame(1, DB::table('assignments')->where('id', $openAuthority)->count(), 'an open TeachingAssignment keeps its authority current');
            $this->assertSame(1, DB::table('assignment_section_audiences')->where('assignment_id', $openAuthority)->count());
            $this->assertSame(1, DB::table('teaching_assignments')->where('employee_id', $owner->id)->count(), 'D6 authority history keeps its own clock');
        });
        Storage::disk($this->disk)->assertMissing($ownedPath);
    }

    #[Test]
    public function an_lms_resource_has_exactly_one_academic_year(): void
    {
        // The audience is pinned to the resource's Offering and to a Section of
        // that Offering's year: a cross-year audience is structurally refused.
        $w = $this->year(null, '2017-04-01', '2018-03-31', 'AY17');
        $later = $this->year($w['school'], '2018-04-01', '2019-03-31', 'AY18');
        $owner = $this->createEmployee($w['school'], ['record_status' => 'active']);
        $id = (string) Str::uuid7();

        // In the resource's creating transaction (an audience is immutable afterwards; fixtures are committed).
        $this->inSchool($w['school'], fn () => $this->refused(fn () => DB::transaction(function () use ($w, $later, $owner, $id): void {
            DB::table('learning_content')->insert([
                'id' => $id, 'school_id' => $w['school']->id, 'subject_offering_id' => $w['offering']->id, 'title' => 'Material', 'status' => 'published',
                'owner_employee_id' => $owner->id, 'sequence' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('learning_content_section_audiences')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'learning_content_id' => $id, 'subject_offering_id' => $w['offering']->id,
                'academic_year_id' => $later['year']->id, 'campus_id' => $later['campus']->id, 'grade_level_id' => $later['grade']->id, 'section_id' => $later['section']->id, 'created_at' => now(),
            ]);
        }), 'foreign key'));
    }

    #[Test]
    public function the_lms_functions_refuse_direct_deletes_young_years_open_authority_documents_and_other_schools(): void
    {
        $w = $this->year(null, '2017-04-01', '2018-03-31', 'AY17');
        $young = $this->year($w['school'], '2024-04-01', '2025-03-31', 'AY24');
        $b = $this->createSchool();
        $owner = $this->createEmployee($w['school'], ['record_status' => 'active']);
        $owned = $this->lms($w, 'learning_content', $owner, '2018-03-31');
        $open = $this->lms($w, 'assignment', $this->createEmployee($w['school'], ['record_status' => 'active']), null);
        $withDocument = $this->lms($w, 'learning_content');
        $this->lmsDocument($w, 'learning_content', $withDocument);
        $youngResource = $this->lms($young, 'assignment');
        $cutoff = '2019-01-01';
        $fn = fn (string $kind, string $id, string $cutoff, bool $dryRun) => (int) DB::selectOne(
            'SELECT '.($kind === 'learning_content' ? 'retention_expire_learning_content' : 'retention_expire_assignment').'(?, ?, ?, ?) AS n',
            [$w['school']->id, $id, $cutoff, $dryRun ? 'true' : 'false'],
        )->n;

        // The runtime role can neither delete an audience nor (E21-RH.5) run the function.
        $this->inSchool($w['school'], function () use ($owned, $fn, $cutoff): void {
            $this->refused(fn () => DB::table('learning_content_section_audiences')->where('learning_content_id', $owned)->delete(), 'permission denied');
            $this->refused(fn () => $fn('learning_content', $owned, $cutoff, true), 'permission denied for function');
        });
        // As the retention identity, the database still refuses every ineligible unit.
        DB::usingConnection(RetentionExpiry::PRIVILEGED_CONNECTION, function () use ($w, $b, $owned, $open, $withDocument, $youngResource, $fn, $cutoff): void {
            $this->inSchool($w['school'], function () use ($owned, $open, $withDocument, $youngResource, $fn, $cutoff): void {
                $this->refused(fn () => $fn('learning_content', $owned, now('UTC')->subYears(7)->addDays(3)->toDateString(), true), 'retention_floor');
                $this->refused(fn () => $fn('assignment', $youngResource, $cutoff, true), 'retention_floor');
                $this->refused(fn () => $fn('assignment', $open, $cutoff, true), 'retention_lms_authority');
                $this->refused(fn () => $fn('learning_content', $withDocument, $cutoff, true), 'retention_lms_dependency');
                $this->assertSame(2, DB::transaction(fn () => $fn('learning_content', $owned, $cutoff, true)), 'a dry run counts the resource and its audience');
            });
            $this->inSchool($b, fn () => $this->refused(fn () => $fn('learning_content', $owned, $cutoff, false), 'retention_tenant'));
        });

        $this->assertSame(1, $this->rows($w['school'], 'learning_content', $owned));
        $check = collect(app(DatabaseRoleVerifier::class)->verify())->keyBy('code');
        $this->assertSame(CheckResult::PASS, $check['retention_functions_narrow']->status);
        $this->assertSame(CheckResult::PASS, $check['privileged_retention_functions_closed']->status);
    }

    /** E21-RH.5: the whole LMS unit through its database function, as $connection: its result or the refusal. */
    private function unit(School $school, string $kind, string $id, string $cutoff, string $connection = RetentionExpiry::PRIVILEGED_CONNECTION): mixed
    {
        try {
            return DB::usingConnection($connection, fn () => $this->inSchool($school, fn () => DB::transaction(
                fn () => DB::select('SELECT o_disk, o_path FROM retention_expire_lms_resource(?, ?, ?, ?)', [$kind, $school->id, $id, $cutoff]),
            )));
        } catch (QueryException $e) {
            return $e->getMessage();
        }
    }

    #[Test]
    public function lms_units_run_whole_as_the_retention_identity_with_their_documents_and_obey_database_holds(): void
    {
        $this->at('2026-06-15');
        $a = $this->year(null, '2017-04-01', '2018-03-31', 'AYA');
        $b = $this->year(null, '2017-04-01', '2018-03-31', 'AYB');
        $young = $this->year($a['school'], '2024-04-01', '2025-03-31', 'AYY');
        $contentA = $this->lms($a, 'learning_content');
        $pathA = $this->lmsDocument($a, 'learning_content', $contentA);
        $contentB = $this->lms($b, 'learning_content');
        $youngContent = $this->lms($young, 'learning_content');
        $this->lmsDocument($young, 'learning_content', $youngContent);
        $cutoff = AcademicYearRetention::cutoff($a['school'], 7);
        $documents = fn (School $school, string $id): int => $this->inSchool($school, fn () => DB::table('documents')->where('learning_content_id', $id)->count());
        $holds = app(RetentionHolds::class);

        // The runtime role cannot run the unit; the owner login is refused by the identity check.
        $this->assertStringContainsString('permission denied for function retention_expire_lms_resource', (string) $this->unit($a['school'], 'learning_content', $contentA, $cutoff, 'pgsql'));
        $this->assertStringContainsString('retention_privilege', (string) $this->unit($a['school'], 'learning_content', $contentA, $cutoff, RetentionHolds::MAINTENANCE_CONNECTION));
        // (E21-RH.5 also proved here that the retention identity could not delete a Document directly. Since
        // E21-RH.6 it may -- the Student/Guardian/Employee units remove their Documents -- and every such
        // delete passes its hold guard: RetentionDeleteBoundaryTest.)

        // An ineligible unit (young year) is refused and its Document deletion rolls back with it.
        $this->assertStringContainsString('retention_floor', (string) $this->unit($a['school'], 'learning_content', $youngContent, $cutoff));
        $this->assertSame(1, $documents($a['school'], $youngContent), 'the Document deletion rolled back');

        // School A held in the database only: refused inside PostgreSQL; the PHP unit keeps it (never an error).
        $holds->place($a['school']->id, 'litigation', 'LMS-HOLD-1');
        $this->assertStringContainsString('retention_hold', (string) $this->unit($a['school'], 'learning_content', $contentA, $cutoff));
        $this->assertRun(['content' => ['eligible' => 1, 'deleted' => 0, 'errors' => 0]], $this->schoolRun($a['school']));
        $this->assertSame(1, $this->rows($a['school'], 'learning_content', $contentA));
        $this->assertSame(1, $documents($a['school'], $contentA));

        // The platform hold blocks School B too.
        $holds->place(null, 'regulatory_inquiry', 'LMS-HOLD-2');
        $this->assertStringContainsString('retention_hold', (string) $this->unit($b['school'], 'learning_content', $contentB, AcademicYearRetention::cutoff($b['school'], 7)));
        $holds->release(null, 'inquiry_closed', 'LMS-HOLD-2');
        $this->assertRun(['content' => ['deleted' => 1, 'errors' => 0]], $this->schoolRun($b['school']));

        // Released: the whole unit, every statement on the retention session; Document rows and bytes go with it.
        $holds->release($a['school']->id, 'matter_concluded', 'LMS-HOLD-1');
        $used = [];
        Event::listen(QueryExecuted::class, function (QueryExecuted $e) use (&$used): void {
            $used[] = $e->connectionName;
        });
        $this->assertSame(['deleted' => 1, 'errors' => 0], array_intersect_key(app(LmsResourceRetention::class)->prune('learning_content', $a['school'], $cutoff, 500, false, false), ['deleted' => 0, 'errors' => 0]));
        $this->assertSame([RetentionExpiry::PRIVILEGED_CONNECTION], array_values(array_unique($used)));
        $this->assertSame(0, $this->rows($a['school'], 'learning_content', $contentA));
        $this->assertSame(0, $documents($a['school'], $contentA));
        Storage::disk($this->disk)->assertMissing($pathA);
        $this->assertSame(1, $this->rows($a['school'], 'learning_content', $youngContent), 'the young resource stays');

        // A duplicate run (the resource already gone) changes nothing and reports nothing as an error.
        $this->assertStringContainsString('retention_lms', (string) $this->unit($a['school'], 'learning_content', $contentA, $cutoff));
    }

    #[Test]
    public function lms_document_bytes_are_removed_from_real_minio_only_with_their_resource(): void
    {
        config(['documents.disk' => 's3']);
        $this->disk = 's3';
        $w = $this->year(null, '2017-04-01', '2018-03-31', 'AY17');
        $young = $this->year($w['school'], '2024-04-01', '2025-03-31', 'AY24');
        $gone = $this->lms($w, 'learning_content');
        $kept = $this->lms($young, 'learning_content');
        $paths = [$this->lmsDocument($w, 'learning_content', $gone), $this->lmsDocument($young, 'learning_content', $kept)];

        try {
            $this->at('2026-06-15');
            $this->assertRun(['content' => ['deleted' => 1, 'dependency_blocked' => 0, 'errors' => 0]], $this->schoolRun($w['school']));
            $this->assertFalse(Storage::disk('s3')->exists($paths[0]));
            $this->assertTrue(Storage::disk('s3')->exists($paths[1]), 'a retained resource keeps its bytes');
        } finally {
            Storage::disk('s3')->delete($paths);
        }
    }
}
