<?php

namespace Tests\Feature\Students\Houses;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\CampusStudentTransferService;
use App\Domain\Students\Application\Exceptions\HouseRefusedException;
use App\Domain\Students\Application\Exceptions\StudentHouseChangedException;
use App\Domain\Students\Application\Groups\HouseAdministrationService;
use App\Domain\Students\Application\Groups\HouseMembershipReason;
use App\Domain\Students\Application\Groups\HouseResponsibility;
use App\Domain\Students\Application\Groups\StudentHouseService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Application\StudentService;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentGroup;
use App\Domain\Students\Infrastructure\StudentGroupMembership;
use App\Domain\Students\Infrastructure\StudentGroupResponsibility;
use App\Models\Campus;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\SchoolTimezone;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 11.7 (OD-SIS-4): the House pilot end to end -- administration,
 * effective-dated membership, responsibilities, stale forms, lifecycle
 * independence, the Student 360 card, the authorization matrix (allow AND
 * deny), Campus-scoped counts that never leak, tenant isolation and privilege
 * separation (a House or a responsibility never grants anything).
 */
class StudentHouseTest extends TestCase
{
    use CreatesTenancyFixtures;

    private School $school;

    private User $admin;

    private Campus $main;

    private Campus $early;

    private AcademicYear $year;

    private GradeLevel $g5;

    private Section $mainA;

    private Section $earlyA;

    private Student $asha;

    private Student $ravi;

    private string $today;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->school] = $this->createSchoolAdmin();
        $this->today = SchoolTimezone::today($this->school);
        $today = CarbonImmutable::parse($this->today);
        $this->main = $this->createCampus($this->school, ['name' => 'Main', 'code' => 'MAIN']);
        $this->early = $this->createCampus($this->school, ['name' => 'Early Years', 'code' => 'EY']);
        $this->year = $this->createAcademicYear($this->school, ['name' => 'Current', 'code' => 'AYC', 'status' => 'active', 'starts_on' => $today->subMonths(3)->toDateString(), 'ends_on' => $today->addMonths(6)->toDateString()]);
        $this->g5 = $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5', 'sequence' => 5]);
        $this->mainA = $this->createSection($this->year, $this->main, $this->g5, ['name' => 'A', 'code' => 'A']);
        $this->earlyA = $this->createSection($this->year, $this->early, $this->g5, ['name' => 'E', 'code' => 'E']);
        $this->asha = $this->placed('Asha', 'S-1', $this->mainA);
        $this->ravi = $this->placed('Ravi', 'S-2', $this->earlyA);
    }

    private function placed(string $first, string $number, Section $section): Student
    {
        $s = $this->createStudent($this->school, ['first_name' => $first, 'last_name' => 'Rao', 'student_number' => $number]);
        app(StudentEnrollmentService::class)->enroll($s, $section, $number, CarbonImmutable::parse($this->year->starts_on)->toDateString(), $this->admin);

        return $s;
    }

    private function inSchool(callable $fn, ?School $school = null): mixed
    {
        return app(TenantContext::class)->withSchool($school ?? $this->school, $fn);
    }

    private function houses(): HouseAdministrationService
    {
        return app(HouseAdministrationService::class);
    }

    private function service(): StudentHouseService
    {
        return app(StudentHouseService::class);
    }

    private function house(string $name, string $code): StudentGroup
    {
        return $this->houses()->create($this->school, $this->admin, ['name' => $name, 'code' => $code]);
    }

    private function version(Student $student): string
    {
        return $this->service()->history($this->school, $this->admin, $student->id)['version'];
    }

    private function assign(Student $student, StudentGroup $house, ?HouseMembershipReason $reason = null): StudentGroupMembership
    {
        return $this->service()->assign($this->school, $this->admin, $student->id, $house->id, $reason, $this->version($student));
    }

    private function current(Student $student): ?StudentGroupMembership
    {
        return $this->inSchool(fn () => StudentGroupMembership::query()->where('student_id', $student->id)->whereNull('ends_on')->first());
    }

    /** @param  list<string>  $capabilities */
    private function holder(array $capabilities, ?string $campusId = null, ?School $school = null): User
    {
        $user = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($user, $school ?? $this->school), $this->createFixtureRole($capabilities), $campusId);

        return $user;
    }

    private function role(string $key, ?string $campusId = null): User
    {
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $this->school), $key, $campusId);

        return $user;
    }

    private function as(User $user, ?School $school = null): self
    {
        app('auth')->forgetGuards();
        $this->actingAs($user)->post('/app/schools/'.($school ?? $this->school)->id.'/activate');

        return $this;
    }

    /** @return array<string, mixed> */
    private function props(string $url, User $as): array
    {
        return $this->as($as)->get($url)->assertOk()->viewData('page')['props'];
    }

    /** One audit event, found by its type and subject (never by same-second order). */
    private function audited(string $type, array $metadata): SchoolAuditEvent
    {
        $events = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', $type)->get())
            ->filter(fn (SchoolAuditEvent $e) => array_intersect_assoc($metadata, (array) $e->metadata) == $metadata);
        $this->assertCount(1, $events, "exactly one {$type} matching the metadata");

        return $events->first();
    }

    #[Test]
    public function houses_have_unique_normalized_codes_and_names_and_corrections_are_versioned(): void
    {
        $red = $this->house('  Red   House ', 'red');
        $this->assertSame('Red House', $red->name);
        $this->assertSame('RED', $red->code);
        $this->assertSame(1, $red->version);

        foreach ([['name' => 'Blue', 'code' => 'Red'], ['name' => 'red house', 'code' => 'BLUE']] as $i => $duplicate) {
            try {
                $this->houses()->create($this->school, $this->admin, $duplicate);
                $this->fail('a duplicate House must be refused');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($i === 0 ? 'code' : 'name', $e->errors());
            }
        }

        $updated = $this->houses()->update($this->school, $this->admin, $red->id, ['name' => 'Ruby', 'code' => 'RUBY', 'description' => 'Courage', 'display_order' => 2, 'version' => 1]);
        $this->assertSame(2, $updated->version);
        $this->expectException(StudentHouseChangedException::class);
        $this->houses()->update($this->school, $this->admin, $red->id, ['name' => 'Scarlet', 'code' => 'RUBY', 'version' => 1]);
    }

    #[Test]
    public function assigning_changing_and_ending_keep_one_current_membership_and_every_row_of_history(): void
    {
        $red = $this->house('Red', 'RED');
        $blue = $this->house('Blue', 'BLUE');

        $first = $this->assign($this->asha, $red);
        $this->assertSame($this->today, $first->starts_on->toDateString());
        $this->assertSame(HouseMembershipReason::ASSIGNMENT, $first->start_reason);

        try {
            $this->assign($this->asha, $blue);
            $this->fail('a change needs a reason');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        $this->assertSame($first->id, $this->current($this->asha)?->id, 'a refused change changes nothing');

        $second = $this->assign($this->asha, $blue, HouseMembershipReason::REASSIGNMENT);
        $closed = $this->inSchool(fn () => StudentGroupMembership::query()->findOrFail($first->id));
        $this->assertSame(CarbonImmutable::parse($this->today)->subDay()->toDateString(), $closed->ends_on?->toDateString(), 'a same-day change records the empty interval');
        $this->assertSame(HouseMembershipReason::REASSIGNMENT, $closed->end_reason);
        $this->assertSame($second->id, $this->current($this->asha)?->id);

        $history = $this->service()->history($this->school, $this->admin, $this->asha->id);
        $this->assertSame([$blue->id, $red->id], $history['memberships']->pluck('student_group_id')->all(), 'newest first');

        $this->service()->end($this->school, $this->admin, $this->asha->id, HouseMembershipReason::SCHOOL_DECISION, $this->version($this->asha));
        $this->assertNull($this->current($this->asha));
        $this->assertCount(2, $this->service()->history($this->school, $this->admin, $this->asha->id)['memberships']);

        $this->assertSame($this->admin->id, $this->audited('student_house.assigned', ['studentId' => $this->asha->id, 'studentGroupId' => $red->id])->actor_user_id);
        $this->audited('student_house.changed', ['studentId' => $this->asha->id, 'studentGroupId' => $blue->id, 'previousStudentGroupId' => $red->id, 'reason' => 'reassignment']);
        $ended = $this->audited('student_house.ended', ['studentId' => $this->asha->id, 'studentGroupId' => $blue->id, 'reason' => 'school_decision']);
        $this->assertEqualsCanonicalizing(['studentId', 'studentGroupId', 'reason'], array_keys((array) $ended->metadata), 'identifiers and closed codes only');
    }

    #[Test]
    public function a_stale_form_is_refused_on_every_write_and_nothing_changes(): void
    {
        $red = $this->house('Red', 'RED');
        $blue = $this->house('Blue', 'BLUE');
        $opened = $this->version($this->asha);
        $this->assign($this->asha, $red);

        foreach ([
            fn () => $this->service()->assign($this->school, $this->admin, $this->asha->id, $blue->id, HouseMembershipReason::REASSIGNMENT, $opened),
            fn () => $this->service()->end($this->school, $this->admin, $this->asha->id, HouseMembershipReason::SCHOOL_DECISION, $opened),
            fn () => $this->service()->assignResponsibility($this->school, $this->admin, $this->asha->id, HouseResponsibility::PREFECT, $opened),
            fn () => $this->service()->assign($this->school, $this->admin, $this->asha->id, $blue->id, HouseMembershipReason::REASSIGNMENT, str_repeat('0', 64)),
        ] as $stale) {
            try {
                $stale();
                $this->fail('a stale or forged version must be refused');
            } catch (StudentHouseChangedException) {
            }
        }
        $this->assertSame($red->id, $this->current($this->asha)?->student_group_id);

        // Web: the refusal banner, never a last-save-wins write; a missing version is a validation error.
        $this->as($this->admin)->post("/app/students/{$this->asha->id}/house", ['house_id' => $blue->id, 'reason' => 'reassignment', 'version' => $opened])->assertSessionHasErrors('refusal');
        $this->as($this->admin)->post("/app/students/{$this->asha->id}/house", ['house_id' => $blue->id, 'reason' => 'reassignment'])->assertSessionHasErrors('version');
        $this->assertSame($red->id, $this->current($this->asha)?->student_group_id);
    }

    #[Test]
    public function inactive_students_keep_their_house_but_receive_no_new_one_and_inactive_houses_take_no_members(): void
    {
        $red = $this->house('Red', 'RED');
        $blue = $this->house('Blue', 'BLUE');
        $this->assign($this->asha, $red);

        try {
            $this->assign($this->asha, $red, HouseMembershipReason::REASSIGNMENT);
            $this->fail('the same House is refused');
        } catch (HouseRefusedException $e) {
            $this->assertSame('HOUSE_SAME', $e->errorCode());
        }

        $this->houses()->setStatus($this->school, $this->admin, $blue->id, 'inactive', 1);
        try {
            $this->assign($this->ravi, $blue);
            $this->fail('an inactive House takes no members');
        } catch (HouseRefusedException $e) {
            $this->assertSame('HOUSE_INACTIVE', $e->errorCode());
        }

        $this->forceStudentStatus($this->asha, 'inactive');
        $this->assertSame($red->id, $this->current($this->asha)?->student_group_id, 'inactivation never touches the House');
        foreach ([fn () => $this->assign($this->asha, $this->house('Green', 'GREEN'), HouseMembershipReason::REASSIGNMENT), fn () => $this->service()->assignResponsibility($this->school, $this->admin, $this->asha->id, HouseResponsibility::PREFECT, $this->version($this->asha))] as $write) {
            try {
                $write();
                $this->fail('an inactive Student receives nothing new');
            } catch (HouseRefusedException $e) {
                $this->assertSame('STUDENT_NOT_ACTIVE', $e->errorCode());
            }
        }

        $this->forceStudentStatus($this->asha, 'active');
        $this->assertSame($red->id, $this->current($this->asha)?->student_group_id, 'reactivation restores nothing and duplicates nothing');
        $this->assertSame(1, $this->inSchool(fn () => StudentGroupMembership::query()->where('student_id', $this->asha->id)->count()));

        $this->forceStudentStatus($this->asha, 'inactive');
        $this->service()->end($this->school, $this->admin, $this->asha->id, HouseMembershipReason::LEFT_SCHOOL, $this->version($this->asha));
        $this->assertNull($this->current($this->asha), 'ending stays possible for any Student');
    }

    #[Test]
    public function a_house_with_current_members_cannot_be_deactivated_and_houses_are_never_deleted(): void
    {
        $red = $this->house('Red', 'RED');
        $this->assign($this->asha, $red);

        try {
            $this->houses()->setStatus($this->school, $this->admin, $red->id, 'inactive', 1);
            $this->fail('a House with current members stays active');
        } catch (HouseRefusedException $e) {
            $this->assertSame('HOUSE_HAS_MEMBERS', $e->errorCode());
        }
        $this->service()->end($this->school, $this->admin, $this->asha->id, HouseMembershipReason::SCHOOL_DECISION, $this->version($this->asha));
        $inactive = $this->houses()->setStatus($this->school, $this->admin, $red->id, 'inactive', 1);
        $this->assertSame('inactive', $inactive->status);
        $this->audited('student_group.deactivated', ['studentGroupId' => $red->id]);
        $this->assertSame(['Red (inactive)'], array_column($this->props("/app/students/{$this->asha->id}/house", $this->admin)['history'], 'house'), 'history keeps naming the House');
        $this->houses()->setStatus($this->school, $this->admin, $red->id, 'active', 2);
        $this->audited('student_group.reactivated', ['studentGroupId' => $red->id]);

        $deletes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => in_array('DELETE', $r->methods(), true) && str_contains($r->uri(), 'house'));
        $this->assertCount(0, $deletes, 'no route deletes a House or a membership');
    }

    #[Test]
    public function responsibilities_are_unique_where_declared_and_end_with_their_membership(): void
    {
        $red = $this->house('Red', 'RED');
        $blue = $this->house('Blue', 'BLUE');
        $refused = function (callable $write, string $code): void {
            try {
                $write();
                $this->fail("expected {$code}");
            } catch (HouseRefusedException $e) {
                $this->assertSame($code, $e->errorCode());
            }
        };
        $give = fn (Student $s, HouseResponsibility $r) => $this->service()->assignResponsibility($this->school, $this->admin, $s->id, $r, $this->version($s));

        $refused(fn () => $give($this->asha, HouseResponsibility::HOUSE_CAPTAIN), 'NO_CURRENT_HOUSE');
        $this->assign($this->asha, $red);
        $this->assign($this->ravi, $red);

        $captain = $give($this->asha, HouseResponsibility::HOUSE_CAPTAIN);
        $refused(fn () => $give($this->ravi, HouseResponsibility::HOUSE_CAPTAIN), 'RESPONSIBILITY_TAKEN');
        $refused(fn () => $give($this->asha, HouseResponsibility::HOUSE_CAPTAIN), 'RESPONSIBILITY_HELD');
        $give($this->asha, HouseResponsibility::PREFECT);
        $give($this->ravi, HouseResponsibility::PREFECT);
        $give($this->ravi, HouseResponsibility::VICE_CAPTAIN);
        $this->audited('student_house.responsibility_assigned', ['studentId' => $this->asha->id, 'responsibility' => 'house_captain']);

        // A House change ends every responsibility held there, in the same transaction.
        $this->assign($this->asha, $blue, HouseMembershipReason::REASSIGNMENT);
        $this->assertSame(0, $this->inSchool(fn () => StudentGroupResponsibility::query()->where('student_id', $this->asha->id)->whereNull('ends_on')->count()));
        $give($this->ravi, HouseResponsibility::HOUSE_CAPTAIN);

        // Ending one responsibility carries its version.
        $vice = $this->inSchool(fn () => StudentGroupResponsibility::query()->where('student_id', $this->ravi->id)->where('responsibility', 'vice_captain')->firstOrFail());
        try {
            $this->service()->endResponsibility($this->school, $this->admin, $this->ravi->id, $vice->id, $vice->version + 1);
            $this->fail('a stale responsibility version is refused');
        } catch (StudentHouseChangedException) {
        }
        $this->service()->endResponsibility($this->school, $this->admin, $this->ravi->id, $vice->id, $vice->version);
        $this->audited('student_house.responsibility_ended', ['studentId' => $this->ravi->id, 'responsibility' => 'vice_captain']);
        $this->expectException(ModelNotFoundException::class);
        $this->service()->endResponsibility($this->school, $this->admin, $this->ravi->id, $captain->id, 1);
    }

    #[Test]
    public function campus_transfer_and_status_changes_never_change_or_duplicate_a_house(): void
    {
        $red = $this->house('Red', 'RED');
        $membership = $this->assign($this->asha, $red);
        $this->service()->assignResponsibility($this->school, $this->admin, $this->asha->id, HouseResponsibility::PREFECT, $this->version($this->asha));

        $scope = app(CampusScopeResolver::class)->campusScope($this->admin, 'enrollments.manage', $this->school);
        app(CampusStudentTransferService::class)->transfer($this->school, $scope, $this->asha->id, $this->earlyA->id, '7', $this->admin);
        $asha = $this->inSchool(fn () => Student::query()->findOrFail($this->asha->id));
        app(StudentService::class)->changeStatus($asha, 'inactive', 'left_school', $this->admin, $this->freshProof($this->admin));
        app(StudentService::class)->changeStatus($this->inSchool(fn () => Student::query()->findOrFail($this->asha->id)), 'active', 'returned_to_school', $this->admin, $this->freshProof($this->admin));

        $rows = $this->inSchool(fn () => StudentGroupMembership::query()->where('student_id', $this->asha->id)->get());
        $this->assertSame([$membership->id], $rows->pluck('id')->all());
        $this->assertNull($rows->first()->ends_on);
        $this->assertSame(1, $rows->first()->version, 'the membership row was never rewritten');
        $this->assertSame(1, $this->inSchool(fn () => StudentGroupResponsibility::query()->whereNull('ends_on')->count()));
    }

    #[Test]
    public function the_authorization_matrix_allows_house_staff_and_refuses_everyone_else(): void
    {
        $red = $this->house('Red', 'RED');
        $this->assign($this->asha, $red);
        $this->assign($this->ravi, $red);
        $show = "/app/students/{$this->asha->id}/house";

        foreach (['school_admin', 'principal'] as $key) {
            $user = $this->role($key);
            $index = $this->props('/app/students/houses', $user);
            $this->assertSame('whole_school', $index['countsScope']);
            $this->assertSame([2], array_column($index['houses'], 'members'));
            $this->assertTrue($index['canManage']);
            $this->assertTrue($this->props($show, $user)['canAssign']);
        }

        // A Campus principal: counts and rows of their own Campus only; no
        // School-wide history, no management, no assignment.
        $campus = $this->role('principal', $this->main->id);
        $index = $this->props('/app/students/houses', $campus);
        $this->assertSame('campus', $index['countsScope']);
        $this->assertSame([1], array_column($index['houses'], 'members'), 'the School-wide size never leaks through a count');
        $this->assertFalse($index['canManage']);
        $this->as($campus)->get($show)->assertForbidden();
        $this->as($campus)->post('/app/students/houses', ['name' => 'Green', 'code' => 'GREEN'])->assertForbidden();
        $this->as($campus)->post($show, ['house_id' => $red->id, 'version' => str_repeat('0', 64)])->assertForbidden();

        // Teacher and operational roles: nothing.
        foreach (['teacher', 'librarian'] as $key) {
            $user = $this->role($key);
            $this->as($user)->get('/app/students/houses')->assertForbidden();
            $this->as($user)->get($show)->assertForbidden();
            $this->as($user)->get('/app/students/reports/students.houses')->assertForbidden();
        }

        // View-only: reads, never writes.
        $viewer = $this->holder(['students.view', 'students.houses.view']);
        $this->assertFalse($this->props($show, $viewer)['canAssign']);
        $this->as($viewer)->post($show, ['house_id' => $red->id, 'version' => str_repeat('0', 64)])->assertForbidden();
        $this->as($viewer)->post('/app/students/houses', ['name' => 'Green', 'code' => 'GREEN'])->assertForbidden();

        // Assign without manage: memberships only, never House administration.
        $assigner = $this->holder(['students.view', 'students.houses.view', 'students.houses.assign']);
        $this->as($assigner)->post('/app/students/houses', ['name' => 'Green', 'code' => 'GREEN'])->assertForbidden();
        $this->as($assigner)->post("{$show}/end", ['reason' => 'school_decision', 'version' => $this->version($this->asha)])->assertRedirect($show);
        $this->assertNull($this->current($this->asha));

        // House view without students.view: the Student is unknown.
        $this->as($this->holder(['students.houses.view']))->get($show)->assertNotFound();

        // Authority is re-read in the service, not only at the route.
        $this->expectException(AuthorizationException::class);
        $this->service()->assign($this->school, $viewer, $this->asha->id, $red->id, null, $this->version($this->asha));
    }

    #[Test]
    public function another_school_never_sees_or_touches_these_houses_or_students(): void
    {
        $red = $this->house('Red', 'RED');
        $this->assign($this->asha, $red);
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        $otherHouse = $this->houses()->create($other, $otherAdmin, ['name' => 'Red', 'code' => 'RED']);
        $this->assertNotSame($red->id, $otherHouse->id, 'the same name and code in another School never collide');

        $this->as($otherAdmin, $other);
        $this->get("/app/students/{$this->asha->id}/house")->assertNotFound();
        $this->post("/app/students/{$this->asha->id}/house", ['house_id' => $otherHouse->id, 'version' => str_repeat('0', 64)])->assertNotFound();
        $this->assertSame([0], array_column($this->get('/app/students/houses')->viewData('page')['props']['houses'], 'members'));
        $this->assertSame([], $this->get('/app/students/reports/students.houses')->viewData('page')['props']['rows']['data']);

        // A foreign House id is unknown to this School's assignment.
        $this->expectException(ModelNotFoundException::class);
        $this->service()->assign($this->school, $this->admin, $this->ravi->id, $otherHouse->id, null, $this->version($this->ravi));
    }

    #[Test]
    public function the_student_profile_shows_the_house_card_only_within_the_viewers_scope(): void
    {
        $red = $this->house('Red', 'RED');
        $this->assign($this->asha, $red);
        $this->service()->assignResponsibility($this->school, $this->admin, $this->asha->id, HouseResponsibility::HOUSE_CAPTAIN, $this->version($this->asha));

        $card = $this->props("/app/students/{$this->asha->id}", $this->admin)['sections']['house'];
        $this->assertSame('Red', $card['house']);
        $this->assertSame(['House Captain'], $card['responsibilities']);
        $this->assertSame("/app/students/{$this->asha->id}/house", $card['link']);

        // A Campus viewer (who reaches Campus surfaces, never this whole-School
        // profile) gets the summary only for a Student in their scope.
        $campus = $this->role('principal', $this->main->id);
        $scope = app(CampusScopeResolver::class)->campusScope($campus, StudentHouseService::VIEW, $this->school);
        $this->assertSame('Red', $this->service()->summary($this->school, $scope, $this->asha)['house'] ?? null);
        $this->assign($this->ravi, $red);
        $this->assertNull($this->service()->summary($this->school, $scope, $this->ravi), 'out of scope is absent');
        $this->as($campus)->get("/app/students/{$this->asha->id}")->assertForbidden();

        $this->assertArrayNotHasKey('house', $this->props("/app/students/{$this->asha->id}", $this->holder(['students.view']))['sections'], 'absent, never hidden');
    }

    #[Test]
    public function a_house_or_responsibility_never_changes_anyones_capabilities(): void
    {
        $resolver = app(CapabilityResolver::class);
        $teacher = $this->role('teacher');
        $campus = $this->role('principal', $this->main->id);
        $before = array_map(fn (User $u) => $resolver->schoolCapabilities($u, $this->school), [$this->admin, $teacher, $campus]);

        $red = $this->house('Red', 'RED');
        $this->assign($this->asha, $red);
        $this->service()->assignResponsibility($this->school, $this->admin, $this->asha->id, HouseResponsibility::HOUSE_CAPTAIN, $this->version($this->asha));
        $this->service()->assignResponsibility($this->school, $this->admin, $this->asha->id, HouseResponsibility::PREFECT, $this->version($this->asha));

        $after = array_map(function (User $u) use ($resolver): array {
            $resolver->forgetCache($u, $this->school);

            return $resolver->schoolCapabilities($u, $this->school);
        }, [$this->admin, $teacher, $campus]);
        $this->assertSame($before, $after);
        $this->as($teacher)->get("/app/students/{$this->asha->id}/house")->assertForbidden();
    }
}
