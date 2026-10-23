<?php

namespace Tests\Feature\Transport;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Transport\Application\TransportRiderImport;
use App\Domain\Transport\Application\TransportStudentAssignmentService;
use App\Domain\Transport\Infrastructure\TransportRoute;
use App\Domain\Transport\Infrastructure\TransportStop;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.9C-4 (ADR 0076 §72, PV1-09): add-only bulk rider assignment. A CSV is
 * parsed (bounded), previewed (read-only, signed) and committed ALL OR
 * NOTHING through the one assignment path; the commit re-reads authority and
 * every row. Students by student number only, routes by code, stops by their
 * number on the route; a Campus-scoped actor gets one generic refusal for
 * anything outside their Campuses.
 */
class TransportRiderImportTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    /** @var array<string, TransportRoute> */
    private array $route = [];

    /** @var array<string, Section> */
    private array $section = [];

    /** @var array<string, TransportStop> */
    private array $stop = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North']);
        $this->b = $this->createCampus($this->school, ['name' => 'South']);
        $year = $this->createAcademicYear($this->school, ['code' => 'T2627', 'name' => '2026-27', 'status' => 'active']);
        $grade = $this->createGradeLevel($this->school);
        foreach (['a' => $this->a, 'b' => $this->b] as $key => $campus) {
            $this->route[$key] = $this->createTransportRoute($this->school, ['campus_id' => $campus->id, 'code' => 'RT-'.strtoupper($key), 'name' => 'Route '.strtoupper($key), 'status' => 'active']);
            $this->stop[$key.'1'] = $this->createTransportStop($this->route[$key], ['name' => 'First', 'sequence' => 1, 'status' => 'active']);
            $this->stop[$key.'2'] = $this->createTransportStop($this->route[$key], ['name' => 'Second', 'sequence' => 2, 'status' => 'active']);
            $this->section[$key] = $this->createSection($year, $campus, $grade, ['code' => 'S'.strtoupper($key), 'status' => 'active']);
        }
        $this->stop['a3'] = $this->createTransportStop($this->route['a'], ['name' => 'Closed', 'sequence' => 3, 'status' => 'inactive']);
        $this->route['shared'] = $this->createTransportRoute($this->school, ['campus_id' => null, 'code' => 'RT-SHARED', 'name' => 'Shared', 'status' => 'active']);
        $this->route['old'] = $this->createTransportRoute($this->school, ['campus_id' => $this->a->id, 'code' => 'RT-OLD', 'name' => 'Old', 'status' => 'inactive']);
    }

    private function pupil(string $number, ?string $campus, string $status = 'active'): Student
    {
        $student = $this->createStudent($this->school, ['student_number' => $number, 'first_name' => 'Pupil', 'last_name' => $number, 'status' => $status]);
        if ($campus !== null) {
            $this->createStudentEnrollment($student, $this->section[$campus], ['status' => 'active']);
        }

        return $student;
    }

    /** @param  list<?string>  $campusIds */
    private function coordinator(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'transport_coordinator', $campusId);
        }

        return $user;
    }

    private function inSchool(callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($this->school, $fn);
    }

    private function riders(): int
    {
        return $this->inSchool(fn () => TransportStudentAssignment::query()->where('status', 'active')->count());
    }

    /** @param  list<string>  $lines */
    private function upload(User $user, array $lines, string $header = 'student_number,route_code,pickup_stop,dropoff_stop'): TestResponse
    {
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        RateLimiter::clear(md5('transport-rider-import'.'transport-rider-import:'.$user->id)); // named limiters key md5(name.key); the throttle has its own test below

        return $this->post('/app/transport/assignments/import/preview', [
            'file' => UploadedFile::fake()->createWithContent('riders.csv', $header."\n".implode("\n", $lines)."\n"),
        ]);
    }

    /** @return array<string, mixed> the preview prop */
    private function preview(User $user, array $lines): array
    {
        return $this->upload($user, $lines)->assertOk()->viewData('page')['props']['preview'];
    }

    /** @return array<int, string> line => outcome */
    private function outcomes(array $preview): array
    {
        return array_column($preview['rows'], 'outcome', 'line');
    }

    /** @param  array<string, mixed>  $preview */
    private function commit(array $preview, array $overrides = []): TestResponse
    {
        return $this->post('/app/transport/assignments/import/commit', [
            'payload' => $preview['payload'], 'issued_at' => $preview['issuedAt'], 'signature' => $preview['signature'], ...$overrides,
        ]);
    }

    #[Test]
    public function a_valid_file_previews_without_writing_then_commits_every_row_through_the_assignment_path(): void
    {
        $user = $this->coordinator([null]);
        $this->pupil('N-1', 'a');
        $this->pupil('N-2', 'a');
        $this->pupil('S-1', 'b');

        $preview = $this->preview($user, ['N-1,rt-a,1,2', 'N-2,RT-A,,', ' S-1 , RT-B , 2 , 1 ']);
        $this->assertSame([2 => 'ready', 3 => 'ready', 4 => 'ready'], $this->outcomes($preview), 'route codes are case-insensitive; cells are trimmed');
        $this->assertTrue($preview['committable']);
        $this->assertSame(['line', 'studentNumber', 'routeCode', 'pickupStop', 'dropoffStop', 'studentName', 'outcome', 'message'], array_keys($preview['rows'][0]), 'no internal id, Campus or profile field');
        $this->assertSame(0, $this->riders(), 'a preview never writes');

        $this->commit($preview)->assertRedirect('/app/transport/assignments');
        $this->assertSame(3, $this->riders());
        $first = $this->inSchool(fn () => TransportStudentAssignment::query()->whereHas('student', fn ($q) => $q->where('student_number', 'N-1'))->sole());
        $this->assertSame([$this->route['a']->id, $this->stop['a1']->id, $this->stop['a2']->id], [$first->route_id, $first->pickup_stop_id, $first->dropoff_stop_id]);
        $this->assertSame(3, $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'transport.student_assignment.assigned')->count()), 'each row is the canonical assignment, audited as one');
        $batch = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'transport.rider_import.committed')->sole());
        $this->assertEquals(['created' => 3, 'alreadyAssigned' => 0, 'rows' => 3], array_intersect_key($batch->metadata, array_flip(['created', 'alreadyAssigned', 'rows'])));
        $this->assertStringNotContainsString('N-1', json_encode($batch->metadata), 'never a row or a student number in the batch audit');

        // A browser retry of the same commit: everything is already assigned, nothing is written or audited again.
        $this->commit($preview)->assertRedirect('/app/transport/assignments');
        $this->assertSame(3, $this->riders());
        $this->assertSame(1, $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', 'transport.rider_import.committed')->count()));
        $this->assertSame(['already_assigned', 'already_assigned', 'already_assigned'], array_values($this->outcomes($this->preview($user, ['N-1,RT-A,1,2', 'N-2,RT-A,,', 'S-1,RT-B,2,1']))));
    }

    #[Test]
    public function the_file_is_checked_as_a_whole_before_any_row(): void
    {
        $user = $this->coordinator([null]);
        $this->upload($user, ['N-1,RT-A'], 'student_number')->assertSessionHasErrors('file');
        $this->upload($user, ['N-1,RT-A,RT-A'], 'student_number,route_code,route_code')->assertSessionHasErrors('file');
        $this->upload($user, ['N-1,RT-A,x'], 'student_number,route_code,student_name')->assertSessionHasErrors('file');
        $this->upload($user, ['N-1,RT-A,1'], 'student_number,route_code')->assertSessionHasErrors('file');
        $this->upload($user, array_fill(0, TransportRiderImport::MAX_ROWS + 1, 'N-1,RT-A'), 'student_number,route_code')->assertSessionHasErrors('file');
        $this->upload($user, [], 'student_number,route_code')->assertSessionHasErrors('file');
        $this->upload($user, ["N-\xff,RT-A"], 'student_number,route_code')->assertSessionHasErrors('file');
        $this->post('/app/transport/assignments/import/preview', ['file' => UploadedFile::fake()->createWithContent('big.csv', str_repeat('x', TransportRiderImport::MAX_BYTES + 10))])->assertSessionHasErrors('file');
        $this->post('/app/transport/assignments/import/preview', [])->assertSessionHasErrors('file');

        $template = $this->get('/app/transport/assignments/import/template')->assertOk();
        $this->assertStringStartsWith('student_number,route_code,pickup_stop,dropoff_stop', $template->getContent());
        $this->assertSame(0, $this->riders());
    }

    #[Test]
    public function every_row_gets_a_safe_outcome_and_one_bad_row_makes_the_file_uncommittable(): void
    {
        $user = $this->coordinator([null]);
        $this->pupil('N-1', 'a');
        $this->pupil('N-2', 'a');
        $this->pupil('N-3', 'a');
        $this->pupil('N-4', 'a');
        $this->pupil('GONE', 'a', 'inactive');
        $assigned = $this->pupil('N-5', 'a');
        $elsewhere = $this->pupil('N-6', 'a');
        $this->inSchool(fn () => app(TransportStudentAssignmentService::class)->assign($assigned, $this->route['a'], $this->stop['a1'], null));
        $this->inSchool(fn () => app(TransportStudentAssignmentService::class)->assign($elsewhere, $this->route['a'], $this->stop['a2'], null));
        $other = $this->createSchool();
        $this->createStudent($other, ['student_number' => 'X-1', 'status' => 'active']);
        $this->createTransportRoute($other, ['code' => 'RT-X', 'status' => 'active']);

        $preview = $this->preview($user, [
            'N-1,RT-A,1,',      // 2 ready
            'N-1,RT-A,1,',      // 3 the same row again
            'N-2,RT-A,1,',      // 4 conflicting rows for N-2
            'N-2,RT-B,1,',      // 5
            'N-3,RT-A,9,',      // 6 no stop 9 on RT-A
            'N-3x,RT-A,,',      // 7 unknown number
            'X-1,RT-A,,',       // 8 another School's student
            'N-4,RT-X,,',       // 9 another School's route
            'N-4,RT-OLD,,',     // 10 inactive route (also a conflicting row for N-4)
            'GONE,RT-A,,',      // 11 inactive student
            'N-5,RT-A,1,',      // 12 already exactly so
            'N-6,RT-A,1,',      // 13 already, differently (stop 2)
            'N-3,RT-A,3,',      // 14 an inactive stop -- and a conflicting row for N-3
            ',RT-A,,',          // 15 missing number
            'N-7,RT-A,first,',  // 16 not a stop number
        ]);
        $this->assertSame([
            2 => 'ready', 3 => 'duplicate_row', 4 => 'conflict', 5 => 'conflict', 6 => 'conflict', 7 => 'invalid', 8 => 'invalid',
            9 => 'conflict', 10 => 'conflict', 11 => 'invalid', 12 => 'already_assigned', 13 => 'conflict', 14 => 'conflict', 15 => 'invalid', 16 => 'invalid',
        ], $this->outcomes($preview));
        $this->assertFalse($preview['committable']);

        $clean = $this->preview($user, ['N-3,RT-A,9,', 'N-4,RT-X,,', 'N-7,RT-A,,', 'N-2,RT-OLD,,', 'N-1,RT-A,3,']);
        $this->assertSame([2 => 'invalid', 3 => 'not_accessible', 4 => 'invalid', 5 => 'invalid', 6 => 'invalid'], $this->outcomes($clean), 'stop 9 is not on RT-A; another School\'s route is unknown; N-7 does not exist; RT-OLD is inactive; stop 3 is inactive');
        $this->assertSame([null, null, null, null, null], array_column($clean['rows'], 'studentName'), 'a refused row never names its Student');
        $this->assertSame(2, $this->riders(), 'nothing was written');
    }

    #[Test]
    public function a_campus_coordinator_sees_one_generic_refusal_outside_their_campus_and_mixed_files_commit_nothing(): void
    {
        $this->pupil('N-1', 'a');
        $this->pupil('N-2', 'a');
        $south = $this->pupil('S-1', 'b');
        $ridesSouth = $this->pupil('N-9', 'a');
        $this->inSchool(fn () => app(TransportStudentAssignmentService::class)->assign($ridesSouth, $this->route['b'], null, null));
        $north = $this->coordinator([$this->a->id]);

        $preview = $this->preview($north, ['N-1,RT-A,1,', 'S-1,RT-A,,', 'N-2,RT-B,,', 'N-2x,RT-A,,', 'N-9,RT-A,,', 'N-1x,RT-SHARED,,']);
        $this->assertSame([2 => 'ready', 3 => 'not_accessible', 4 => 'not_accessible', 5 => 'not_accessible', 6 => 'not_accessible', 7 => 'not_accessible'], $this->outcomes($preview));
        foreach (array_slice($preview['rows'], 1) as $row) {
            $this->assertNull($row['studentName'], "line {$row['line']}: nothing about a Student outside the Campus");
        }
        $this->assertSame(
            ['Student not found or not accessible for this route.', 'Route not found or not accessible.', 'Student not found or not accessible for this route.', 'Student not found or not accessible for this route.'],
            [$preview['rows'][1]['message'], $preview['rows'][2]['message'], $preview['rows'][3]['message'], $preview['rows'][4]['message']],
            'an existing Student of another Campus, an unknown number and a Student riding another Campus\'s route look the same',
        );
        $this->assertFalse($preview['committable']);

        // An uncommittable preview is never signed, so it cannot be committed at all.
        $this->assertSame([null, null], [$preview['signature'], $preview['issuedAt']]);
        $this->commit($preview)->assertSessionHasErrors('signature');
        $this->assertSame(1, $this->riders());
        $this->assertSame(0, $this->inSchool(fn () => TransportStudentAssignment::query()->where('student_id', $south->id)->count()));

        // A repeated row of a Student riding another Campus's route answers exactly like the first (review L3).
        $this->assertSame([2 => 'not_accessible', 3 => 'not_accessible'], $this->outcomes($this->preview($north, ['N-9,RT-A,,', 'N-9,RT-A,,'])));

        $ok = $this->preview($north, ['N-1,RT-A,1,', 'N-2,RT-A,2,']);
        $this->commit($ok)->assertRedirect('/app/transport/assignments');
        $this->assertSame(3, $this->riders());
    }

    #[Test]
    public function the_commit_is_bound_to_its_preview_actor_and_time(): void
    {
        $user = $this->coordinator([null]);
        $this->pupil('N-1', 'a');
        $preview = $this->preview($user, ['N-1,RT-A,1,']);

        $altered = $preview['payload'];
        $altered[0]['route_code'] = 'RT-B';
        $this->commit($preview, ['payload' => $altered])->assertSessionHasErrors('file');
        $this->commit($preview, ['signature' => str_repeat('0', 64)])->assertSessionHasErrors('file');

        $someoneElse = $this->coordinator([null]);
        $this->actingAs($someoneElse)->post("/app/schools/{$this->school->id}/activate");
        $this->commit($preview)->assertSessionHasErrors('file');

        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->travel(TransportRiderImport::PREVIEW_TTL_MINUTES + 1)->minutes();
        $this->commit($preview)->assertSessionHasErrors('file');
        $this->assertSame(0, $this->riders());

        $viewer = $this->createUserWithCapabilities($this->school, ['transport.assignments.view']);
        $this->actingAs($viewer)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/transport/assignments/import')->assertForbidden();
        $this->commit($preview)->assertForbidden();
    }

    #[Test]
    public function the_commit_re_reads_every_row_and_the_actors_authority(): void
    {
        $user = $this->coordinator([null]);
        $students = [];
        foreach (range(1, 10) as $n) {
            $students[$n] = $this->pupil("N-{$n}", 'a');
        }
        $lines = array_map(fn (int $n) => "N-{$n},RT-A,1,", range(1, 10));
        $preview = $this->preview($user, $lines);
        $this->assertSame(10, $preview['summary']['ready']);

        // After the preview, another operator assigns N-7 differently: the whole batch is refused.
        $this->inSchool(fn () => app(TransportStudentAssignmentService::class)->assign($students[7], $this->route['a'], $this->stop['a2'], null));
        $this->commit($preview)->assertOk()->assertInertia(fn ($p) => $p->where('preview.rows.6.outcome', 'conflict')->where('preview.committable', false));
        $this->assertSame(1, $this->riders(), '9 valid rows + 1 conflicting row = 0 new assignments');

        // Fixed (ended) -- the same file commits all ten.
        $this->inSchool(fn () => app(TransportStudentAssignmentService::class)->end(TransportStudentAssignment::query()->where('student_id', $students[7]->id)->sole()));
        $this->commit($this->preview($user, $lines))->assertRedirect();
        $this->assertSame(10, $this->riders());

        // An identical assignment made after the preview is a no-op, not a failure.
        $more = [$this->pupil('M-1', 'a'), $this->pupil('M-2', 'a')];
        $preview = $this->preview($user, ['M-1,RT-A,1,', 'M-2,RT-A,1,']);
        $this->inSchool(fn () => app(TransportStudentAssignmentService::class)->assign($more[0], $this->route['a'], $this->stop['a1'], null));
        $this->commit($preview)->assertRedirect()->assertSessionHas('flash', 'Assigned 1 rider(s); 1 already assigned.');

        // The route closed, or the Student left, after the preview: refused.
        $closing = [$this->pupil('R-1', 'a'), $this->pupil('R-2', 'a')];
        $preview = $this->preview($user, ['R-1,RT-B,,', 'R-2,RT-A,,']);
        $this->inSchool(fn () => TransportRoute::query()->whereKey($this->route['b']->id)->update(['status' => 'inactive']));
        $this->commit($preview)->assertOk()->assertInertia(fn ($p) => $p->where('preview.committable', false));
        $this->inSchool(fn () => TransportRoute::query()->whereKey($this->route['b']->id)->update(['status' => 'active']));
        $preview = $this->preview($user, ['R-1,RT-B,,', 'R-2,RT-A,,']);
        $this->changeStudentStatus($closing[1], 'inactive');
        $this->commit($preview)->assertOk()->assertInertia(fn ($p) => $p->where('preview.committable', false));
        $this->assertSame(0, $this->inSchool(fn () => TransportStudentAssignment::query()->whereIn('student_id', [$closing[0]->id, $closing[1]->id])->count()));

        // Authority revoked after the preview -- the cached floor still says yes, the commit does not.
        $this->pupil('A-1', 'a');
        $preview = $this->preview($user, ['A-1,RT-A,,']);
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['transport.assignments.manage', $this->school]));
        $this->inSchool(fn () => DB::table('membership_role_assignments')
            ->whereIn('school_membership_id', DB::table('school_memberships')->where('user_id', $user->id)->pluck('id'))
            ->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));
        $this->commit($preview)->assertForbidden();
        $this->assertSame(0, $this->inSchool(fn () => TransportStudentAssignment::query()->whereHas('student', fn ($q) => $q->where('student_number', 'A-1'))->count()));
    }

    #[Test]
    public function the_import_never_reads_or_writes_another_school(): void
    {
        $user = $this->coordinator([null]);
        $other = $this->createSchool();
        $stranger = $this->createUser();
        $this->assignSchoolRole($this->createMembership($stranger, $other), 'transport_coordinator');
        $this->pupil('N-1', 'a');

        $this->actingAs($stranger)->post("/app/schools/{$other->id}/activate");
        $preview = $this->post('/app/transport/assignments/import/preview', [
            'file' => UploadedFile::fake()->createWithContent('r.csv', "student_number,route_code\nN-1,RT-A\n"),
        ])->assertOk()->viewData('page')['props']['preview'];
        $this->assertSame([2 => 'not_accessible'], $this->outcomes($preview), 'RT-A is another School\'s route');
        $this->assertSame(0, $this->riders());
        $this->assertNotNull($user);
    }

    #[Test]
    public function preview_and_commit_stay_inside_the_signed_window(): void
    {
        $user = $this->coordinator([null]);
        $this->pupil('N-1', 'a');
        $preview = $this->preview($user, ['N-1,RT-A,,']);
        $this->commit($preview, ['issued_at' => CarbonImmutable::now()->addMinute()->toIso8601String()])->assertSessionHasErrors('file');
        $this->commit($preview, ['payload' => []])->assertSessionHasErrors('payload');
        $this->assertSame(0, $this->riders());
    }

    #[Test]
    public function a_whole_school_preview_cannot_be_committed_after_demotion_to_a_campus_grant(): void
    {
        // Security review L1: the whole-School premise is re-read at commit, so a coordinator
        // reduced to Campus A cannot use a cached whole-School scope to place a Campus B Student.
        $user = $this->coordinator([null]);
        $this->pupil('S-1', 'b');
        $preview = $this->preview($user, ['S-1,RT-A,,']);
        $this->assertSame([2 => 'ready'], $this->outcomes($preview), 'a whole-School coordinator may place any active Student');

        $membership = $this->inSchool(fn () => DB::table('school_memberships')->where('user_id', $user->id)->value('id'));
        $this->inSchool(fn () => DB::table('membership_role_assignments')->where('school_membership_id', $membership)->update(['revoked_at' => now(), 'revocation_reason' => 'revoked']));
        $this->assignSchoolRole(SchoolMembership::query()->findOrFail($membership), 'transport_coordinator', $this->a->id);
        $this->assertTrue(Gate::forUser($user)->allows('capability', ['transport.assignments.manage', $this->school]), 'the cached floor still says whole-School');

        $this->commit($preview)->assertForbidden();
        $this->assertSame(0, $this->riders());
    }

    #[Test]
    public function preview_and_commit_share_a_per_user_throttle(): void
    {
        $user = $this->coordinator([null]);
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $file = fn () => ['file' => UploadedFile::fake()->createWithContent('r.csv', "student_number,route_code\nN-1,RT-A\n")];
        foreach (range(1, 10) as $attempt) {
            $this->post('/app/transport/assignments/import/preview', $file())->assertOk();
        }
        $this->post('/app/transport/assignments/import/preview', $file())->assertStatus(429);
        $this->post('/app/transport/assignments/import/commit', [])->assertStatus(429);
    }
}
