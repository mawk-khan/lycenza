<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Application\Exceptions\InvalidEnrollmentDateRangeException;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Domain\Students\Infrastructure\StudentLifecycleEvent;
use App\Support\Auth\Mfa\FreshMfaProof;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * ORG.8G (ADR 0076 §65, closes G-6): the Student lifecycle rules PostgreSQL
 * enforces without knowing who acted, proven as the real runtime role. The
 * ORG.8F probes (§64.12) that used to be ACCEPTED are refused here; the rules
 * that already refused stay refused. Only the acting User's identity stays an
 * application trust boundary (an event may name any member).
 */
class StudentLifecycleStructureTest extends TestCase
{
    use CreatesAttendanceFixtures;

    /** @return array<string, mixed> attendanceWorld: year 2026-06-01..2027-03-31, one active placement */
    private function world(): array
    {
        $w = $this->attendanceWorld();
        $w['enrollment'] = $this->enrollStudent($w['section'], '1', '2026-06-01');
        $w['student'] = $this->inSchool($w['school'], fn () => Student::query()->findOrFail($w['enrollment']->student_id));

        return $w;
    }

    /** Runs a raw statement in its own transaction; '' when it is accepted (and rolled back), else the error. */
    private function raw(callable $statement): string
    {
        try {
            DB::transaction(function () use ($statement) {
                $statement();
                throw new \DomainException('accepted');
            });
        } catch (\DomainException) {
            return '';
        } catch (QueryException $e) {
            return $e->getMessage();
        }

        return '';
    }

    private function event(array $w, string $action, ?string $enrollmentId = null): void
    {
        DB::table('student_lifecycle_events')->insert([
            'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'student_id' => $w['student']->id, 'student_enrollment_id' => $enrollmentId,
            'action' => $action, 'reason_code' => match ($action) {
                'student_deactivated', 'enrollment_withdrawn' => 'left_school',
                'student_reactivated' => 'returned_to_school',
                default => 'entered_in_error',
            }, 'acted_by_user_id' => $w['actor']->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function row(array $w, array $columns): array
    {
        return array_merge((array) DB::table('student_enrollments')->where('id', $w['enrollment']->id)->first(), ['id' => (string) Str::uuid7(), 'roll_number' => '99'], $columns);
    }

    #[Test]
    public function application_status_constants_and_the_database_domains_agree(): void
    {
        foreach ([[Student::class, 'students_status_check'], [StudentEnrollment::class, 'student_enrollments_status_check']] as [$model, $constraint]) {
            $definition = (string) DB::selectOne('select pg_get_constraintdef(oid) as d from pg_constraint where conname = ?', [$constraint])->d;
            preg_match_all("/'([a-z_]+)'/", $definition, $values);
            $this->assertSame($model::STATUSES, $values[1], "{$model}::STATUSES mirrors {$constraint}");
        }
    }

    #[Test]
    public function the_runtime_role_can_no_longer_create_a_lifecycle_state_no_service_can(): void
    {
        $w = $this->world();
        $this->assertSame('school_os_app', DB::selectOne('select current_user as u')->u);

        $this->inSchool($w['school'], function () use ($w) {
            $sid = $w['student']->id;
            // ORG.8F: accepted before ORG.8G.
            $this->assertStringContainsString('students_lifecycle_evidence', $this->raw(fn () => DB::table('students')->where('id', $sid)->update(['status' => 'inactive'])));
            $this->assertStringContainsString('students_status_check', $this->raw(function () use ($w, $sid) {
                $this->event($w, 'student_reactivated'); // even with evidence, an unknown value is refused
                DB::table('students')->where('id', $sid)->update(['status' => 'expelled']);
            }));
            $this->assertStringContainsString('born_active', $this->raw(fn () => DB::table('student_enrollments')->insert($this->row($w, ['status' => 'withdrawn', 'ends_on' => '2026-07-01']))));
            $this->assertStringContainsString('born_active', $this->raw(fn () => DB::table('student_enrollments')->insert($this->row($w, ['status' => 'completed', 'ends_on' => '2026-07-01']))));
            // An unknown placement status is refused (whichever structural rule PostgreSQL reports first).
            $this->assertMatchesRegularExpression('/student_enrollments_(status|end_shape)_check|retention_eligibility_guard/', $this->raw(fn () => DB::table('student_enrollments')->where('id', $w['enrollment']->id)->update(['status' => 'zzz'])));
            $this->assertStringContainsString('born_active', $this->raw(fn () => DB::table('students')->insert([
                'id' => (string) Str::uuid7(), 'school_id' => $w['school']->id, 'student_number' => 'RAW-1', 'first_name' => 'R', 'last_name' => 'S',
                'date_of_birth' => '2015-01-01', 'status' => 'inactive', 'created_at' => now(), 'updated_at' => now(),
            ])));
            // Terminal shape and the year bound.
            $this->assertStringContainsString('student_enrollments_end_shape_check', $this->raw(fn () => DB::table('student_enrollments')->where('id', $w['enrollment']->id)->update(['status' => 'completed'])));
            $this->assertStringContainsString('student_enrollments_end_within_year', $this->raw(fn () => DB::table('student_enrollments')->where('id', $w['enrollment']->id)->update(['status' => 'completed', 'ends_on' => '2099-01-01'])));
            $this->assertStringContainsString('student_enrollments_date_range_check', $this->raw(fn () => DB::table('student_enrollments')->where('id', $w['enrollment']->id)->update(['status' => 'transferred', 'ends_on' => '2026-01-01'])));
            $this->assertSame('', $this->raw(fn () => DB::table('student_enrollments')->where('id', $w['enrollment']->id)->update(['status' => 'completed', 'ends_on' => '2027-03-31'])), 'a completion ends on the year end');
            $this->assertStringContainsString('not its academic year end', $this->raw(fn () => DB::table('student_enrollments')->where('id', $w['enrollment']->id)->update(['status' => 'completed', 'ends_on' => '2026-10-01'])), 'an early "completion" is refused (security review M2)');
            $this->assertSame('', $this->raw(fn () => DB::table('student_enrollments')->where('id', $w['enrollment']->id)->update(['status' => 'transferred', 'ends_on' => '2026-06-01'])), 'ending on the start day stays allowed');
            // An ended row is final for the runtime role (retention guard), so it is never reopened, re-dated or re-labelled.
            foreach ([['status' => 'active', 'ends_on' => null], ['ends_on' => '2026-08-01'], ['status' => 'completed', 'ends_on' => '2027-03-31']] as $change) {
                $this->assertMatchesRegularExpression('/an ended record keeps its end and status|end_shape_check/', $this->raw(function () use ($w, $change) {
                    DB::table('student_enrollments')->where('id', $w['enrollment']->id)->update(['status' => 'transferred', 'ends_on' => '2026-07-31']);
                    DB::table('student_enrollments')->where('id', $w['enrollment']->id)->update($change);
                }), 'security review M1: ended placements are final');
            }

            // Already refused before ORG.8G, still refused.
            $this->assertStringContainsString('student_enrollments_lifecycle_evidence', $this->raw(fn () => DB::table('student_enrollments')->where('id', $w['enrollment']->id)->update(['status' => 'withdrawn', 'ends_on' => '2026-07-01'])));
            $this->assertStringContainsString('student_enrollments_lifecycle_evidence', $this->raw(function () use ($w) {
                $this->event($w, 'enrollment_cancelled', $w['enrollment']->id);
                DB::table('student_enrollments')->where('id', $w['enrollment']->id)->update(['status' => 'withdrawn', 'ends_on' => '2026-07-01']);
            }), 'the wrong action\'s evidence never counts');
            $this->assertStringContainsString('students_lifecycle_evidence', $this->raw(function () use ($w, $sid) {
                $this->event($w, 'student_reactivated');
                DB::table('students')->where('id', $sid)->update(['status' => 'inactive']);
            }), 'evidence for the other direction never counts');
            $this->assertStringContainsString('students_lifecycle_evidence', $this->raw(function () use ($w, $sid) {
                $this->event($w, 'enrollment_withdrawn', $w['enrollment']->id);
                DB::table('students')->where('id', $sid)->update(['status' => 'inactive']);
            }), 'placement evidence never counts for the Student');
        });
    }

    #[Test]
    public function a_matching_event_lets_the_transition_through_and_events_stay_append_only(): void
    {
        $w = $this->world();
        $this->inSchool($w['school'], function () use ($w) {
            $sid = $w['student']->id;
            $this->assertSame('', $this->raw(function () use ($w, $sid) {
                $this->event($w, 'student_deactivated');
                DB::table('students')->where('id', $sid)->update(['status' => 'inactive']);
                $this->event($w, 'student_reactivated');
                DB::table('students')->where('id', $sid)->update(['status' => 'active']);
            }));
            $this->assertSame('', $this->raw(function () use ($w) {
                $this->event($w, 'enrollment_withdrawn', $w['enrollment']->id);
                DB::table('student_enrollments')->where('id', $w['enrollment']->id)->update(['status' => 'withdrawn', 'ends_on' => '2026-07-01']);
            }));
            // Without the reactivation event the way back is refused; an ended placement never reopens.
            $this->assertStringContainsString('students_lifecycle_evidence', $this->raw(function () use ($w, $sid) {
                $this->event($w, 'student_deactivated');
                DB::table('students')->where('id', $sid)->update(['status' => 'inactive']);
                DB::table('students')->where('id', $sid)->update(['status' => 'active']);
            }));
            $this->assertStringContainsString('retention_eligibility_guard', $this->raw(function () use ($w) {
                $this->event($w, 'enrollment_withdrawn', $w['enrollment']->id);
                DB::table('student_enrollments')->where('id', $w['enrollment']->id)->update(['status' => 'withdrawn', 'ends_on' => '2026-07-01']);
                DB::table('student_enrollments')->where('id', $w['enrollment']->id)->update(['status' => 'active', 'ends_on' => null]);
            }));
            $this->event($w, 'student_deactivated');
            $this->assertStringContainsString('permission denied', $this->raw(fn () => DB::table('student_lifecycle_events')->update(['reason_code' => 'entered_in_error'])));
            $this->assertStringContainsString('permission denied', $this->raw(fn () => DB::table('student_lifecycle_events')->delete()));
        });
    }

    #[Test]
    public function the_services_keep_a_placement_inside_its_year_and_never_date_an_end_in_the_future(): void
    {
        $w = $this->world();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', $w['school']->timezone));
        $admin = $this->schoolAdminFor($w['school']);
        $proof = new FreshMfaProof($admin->id, CarbonImmutable::now());
        $service = app(StudentEnrollmentService::class);
        $placement = fn () => $this->inSchool($w['school'], fn () => $w['enrollment']->fresh());

        foreach (['2099-01-01', '2027-04-01', '2026-10-16'] as $date) {
            $this->assertThrows(fn () => $service->withdraw($placement(), $date, 'left_school', $admin, $proof), InvalidEnrollmentDateRangeException::class);
            $this->assertThrows(fn () => $service->cancel($placement(), $date, 'did_not_join', $admin, $proof), InvalidEnrollmentDateRangeException::class);
        }
        $this->assertThrows(fn () => $service->complete($placement(), '2027-04-01', $admin), InvalidEnrollmentDateRangeException::class);
        $this->assertThrows(fn () => $service->complete($placement(), '2026-10-15', $admin), InvalidEnrollmentDateRangeException::class); // early: withdraw instead
        $this->assertThrows(fn () => $service->complete($placement(), '2027-03-31', $admin), InvalidEnrollmentDateRangeException::class); // the year end, not yet come
        $this->assertSame('active', $placement()->status);

        $service->withdraw($placement(), '2026-06-01', 'left_school', $admin, $proof);
        $this->assertSame(['withdrawn', '2026-06-01'], [$placement()->status, $placement()->ends_on->toDateString()], 'backdating to the start day stays allowed');
    }

    #[Test]
    public function fixtures_reach_ended_states_through_their_evidence(): void
    {
        $w = $this->world();
        $student = $this->createStudent($w['school'], ['status' => 'inactive']);
        $ended = $this->createStudentEnrollment($student, $w['section'], ['roll_number' => '77', 'status' => 'withdrawn', 'starts_on' => '2026-06-01', 'ends_on' => '2026-07-31']);

        $this->inSchool($w['school'], function () use ($student, $ended) {
            $this->assertSame('inactive', $student->fresh()->status);
            $this->assertSame(['withdrawn', '2026-07-31'], [$ended->fresh()->status, $ended->fresh()->ends_on->toDateString()]);
            $this->assertSame(['enrollment_withdrawn', 'student_deactivated'], StudentLifecycleEvent::query()->where('student_id', $student->id)->orderBy('action')->pluck('action')->all());
        });
    }
}
