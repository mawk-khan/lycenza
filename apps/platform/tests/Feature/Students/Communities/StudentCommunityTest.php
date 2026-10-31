<?php

namespace Tests\Feature\Students\Communities;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\GradeLevel;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Students\Application\CampusStudentTransferService;
use App\Domain\Students\Application\Exceptions\CommunityRefusedException;
use App\Domain\Students\Application\Exceptions\StudentCommunityChangedException;
use App\Domain\Students\Application\Groups\ActivityCategory;
use App\Domain\Students\Application\Groups\ClubResponsibility;
use App\Domain\Students\Application\Groups\CommunityAdministrationService;
use App\Domain\Students\Application\Groups\CommunityMembershipReason;
use App\Domain\Students\Application\Groups\HouseAdministrationService;
use App\Domain\Students\Application\Groups\HouseResponsibility;
use App\Domain\Students\Application\Groups\StudentCommunityService;
use App\Domain\Students\Application\Groups\StudentGroupType;
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
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * SIS 11.8 (OD-SIS-5A Club, OD-SIS-5B Activity): Clubs and Activities end
 * to end -- catalogue, several memberships at once, per-group uniqueness,
 * history across leave and rejoin, Club responsibilities, stale forms,
 * lifecycle independence, the authorization matrix (allow AND deny),
 * Campus-scoped counts, tenant isolation, the profile card and privilege
 * separation. Houses keep their own contract (StudentHouseTest).
 */
class StudentCommunityTest extends TestCase
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

    private function admin(): CommunityAdministrationService
    {
        return app(CommunityAdministrationService::class);
    }

    private function service(): StudentCommunityService
    {
        return app(StudentCommunityService::class);
    }

    private function club(string $name, string $code): StudentGroup
    {
        return $this->admin()->create($this->school, $this->admin, StudentGroupType::CLUB, ['name' => $name, 'code' => $code]);
    }

    private function activity(string $name, string $code, ?ActivityCategory $category = null): StudentGroup
    {
        return $this->admin()->create($this->school, $this->admin, StudentGroupType::ACTIVITY, ['name' => $name, 'code' => $code, 'activity_category' => $category?->value]);
    }

    private function join(Student $student, StudentGroup $group, ?User $as = null): StudentGroupMembership
    {
        return $this->service()->join($this->school, $as ?? $this->admin, $student->id, $group->id);
    }

    private function leave(StudentGroupMembership $m, CommunityMembershipReason $reason = CommunityMembershipReason::WITHDREW): StudentGroupMembership
    {
        $fresh = $this->inSchool(fn () => StudentGroupMembership::query()->findOrFail($m->id));

        return $this->service()->leave($this->school, $this->admin, $fresh->student_id, $fresh->id, $reason, $fresh->version);
    }

    /** @return list<string> group ids of the Student's current Clubs and Activities */
    private function current(Student $student): array
    {
        return $this->inSchool(fn () => StudentGroupMembership::query()->where('student_id', $student->id)->whereIn('group_type', ['club', 'activity'])->whereNull('ends_on')->orderBy('id')->pluck('student_group_id')->all());
    }

    private function refused(callable $write, string $code): void
    {
        try {
            $write();
            $this->fail("expected {$code}");
        } catch (CommunityRefusedException $e) {
            $this->assertSame($code, $e->errorCode());
        }
    }

    /** @param  list<string>  $capabilities */
    private function holder(array $capabilities, ?string $campusId = null): User
    {
        $user = $this->createUser();
        LocalCatalogueFixtures::grantRole($this->createMembership($user, $this->school), $this->createFixtureRole($capabilities), $campusId);

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

    /** One audit event, found by its type and metadata (never by same-second order). */
    private function audited(string $type, array $metadata): SchoolAuditEvent
    {
        $events = $this->inSchool(fn () => SchoolAuditEvent::query()->where('event_type', $type)->get())
            ->filter(fn (SchoolAuditEvent $e) => array_intersect_assoc($metadata, (array) $e->metadata) == $metadata);
        $this->assertCount(1, $events, "exactly one {$type} matching the metadata");

        return $events->first();
    }

    #[Test]
    public function clubs_and_activities_have_their_own_closed_catalogue(): void
    {
        $chess = $this->club('  Chess   Club ', 'chess');
        $this->assertSame(['Chess Club', 'CHESS', StudentGroupType::CLUB, 1], [$chess->name, $chess->code, $chess->group_type, $chess->version]);
        $this->audited('student_group.created', ['studentGroupId' => $chess->id, 'groupType' => 'club', 'code' => 'CHESS']);

        // Unique per School AND type: a House may share a Club's name and code.
        $house = app(HouseAdministrationService::class)->create($this->school, $this->admin, ['name' => 'Chess Club', 'code' => 'CHESS']);
        $this->assertSame(StudentGroupType::HOUSE, $house->group_type);
        try {
            $this->club('chess club', 'OTHER');
            $this->fail('a duplicate Club name is refused');
        } catch (ValidationException $e) {
            $this->assertSame(['Another Club already has this name.'], $e->errors()['name']);
        }

        $football = $this->activity('Football', 'FOOT', ActivityCategory::SPORTS);
        $this->assertSame(ActivityCategory::SPORTS, $football->activity_category);
        $this->assertNull($this->admin()->create($this->school, $this->admin, StudentGroupType::CLUB, ['name' => 'Art', 'code' => 'ART', 'activity_category' => 'sports'])->activity_category, 'only an Activity has a category');
        try {
            $this->activity('Bad', 'BAD', null);
            $this->admin()->create($this->school, $this->admin, StudentGroupType::ACTIVITY, ['name' => 'Worse', 'code' => 'WORSE', 'activity_category' => 'chess_grandmasters']);
            $this->fail('a category outside the closed list is refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('activity_category', $e->errors());
        }

        $updated = $this->admin()->update($this->school, $this->admin, StudentGroupType::ACTIVITY, $football->id, ['name' => 'Football', 'code' => 'FOOT', 'activity_category' => 'other', 'version' => 1]);
        $this->assertSame([2, ActivityCategory::OTHER], [$updated->version, $updated->activity_category]);
        $this->expectException(StudentCommunityChangedException::class);
        $this->admin()->update($this->school, $this->admin, StudentGroupType::ACTIVITY, $football->id, ['name' => 'Soccer', 'code' => 'FOOT', 'version' => 1]);
    }

    #[Test]
    public function a_type_service_never_reaches_another_types_groups(): void
    {
        $chess = $this->club('Chess', 'CHESS');
        $red = app(HouseAdministrationService::class)->create($this->school, $this->admin, ['name' => 'Red', 'code' => 'RED']);

        foreach ([
            fn () => $this->admin()->update($this->school, $this->admin, StudentGroupType::ACTIVITY, $chess->id, ['name' => 'X', 'code' => 'X', 'version' => 1]),
            fn () => app(HouseAdministrationService::class)->setStatus($this->school, $this->admin, $chess->id, 'inactive', 1),
            fn () => $this->service()->join($this->school, $this->admin, $this->asha->id, $red->id),
        ] as $crossing) {
            try {
                $crossing();
                $this->fail('a group of another type is unknown');
            } catch (ModelNotFoundException) {
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->admin()->create($this->school, $this->admin, StudentGroupType::HOUSE, ['name' => 'Blue', 'code' => 'BLUE']);
    }

    #[Test]
    public function a_student_joins_several_clubs_and_activities_but_never_one_twice(): void
    {
        [$chess, $debate] = [$this->club('Chess', 'CHESS'), $this->club('Debate', 'DEBATE')];
        [$football, $music] = [$this->activity('Football', 'FOOT', ActivityCategory::SPORTS), $this->activity('Music', 'MUSIC', ActivityCategory::PERFORMING_ARTS)];

        foreach ([$chess, $debate, $football, $music] as $group) {
            $m = $this->join($this->asha, $group);
            $this->assertSame([$this->today, CommunityMembershipReason::JOINED], [$m->starts_on->toDateString(), $m->start_reason]);
        }
        $this->assertEqualsCanonicalizing([$chess->id, $debate->id, $football->id, $music->id], $this->current($this->asha));
        $this->audited('student_community.joined', ['studentId' => $this->asha->id, 'studentGroupId' => $debate->id, 'groupType' => 'club']);
        $this->audited('student_community.joined', ['studentId' => $this->asha->id, 'studentGroupId' => $music->id, 'groupType' => 'activity']);

        $this->refused(fn () => $this->join($this->asha, $chess), 'ALREADY_MEMBER');
        $this->refused(fn () => $this->join($this->asha, $music), 'ALREADY_MEMBER');
        $this->assertCount(4, $this->current($this->asha));

        // A House stays one per Student alongside any number of communities.
        $houses = app(HouseAdministrationService::class);
        $red = $houses->create($this->school, $this->admin, ['name' => 'Red', 'code' => 'RED']);
        $blue = $houses->create($this->school, $this->admin, ['name' => 'Blue', 'code' => 'BLUE']);
        $house = app(StudentHouseService::class);
        $house->assign($this->school, $this->admin, $this->asha->id, $red->id, null, $house->history($this->school, $this->admin, $this->asha->id)['version']);
        $this->expectException(ValidationException::class);
        $house->assign($this->school, $this->admin, $this->asha->id, $blue->id, null, $house->history($this->school, $this->admin, $this->asha->id)['version']);
    }

    #[Test]
    public function leaving_and_rejoining_keeps_every_period(): void
    {
        $chess = $this->club('Chess', 'CHESS');
        $first = $this->join($this->asha, $chess);
        $ended = $this->leave($first);
        $this->assertSame([CarbonImmutable::parse($this->today)->subDay()->toDateString(), CommunityMembershipReason::WITHDREW], [$ended->ends_on?->toDateString(), $ended->end_reason]);
        $this->audited('student_community.left', ['studentId' => $this->asha->id, 'studentGroupId' => $chess->id, 'reason' => 'withdrew']);
        $this->assertSame([], $this->current($this->asha));

        $second = $this->join($this->asha, $chess);
        $this->assertNotSame($first->id, $second->id);
        $history = $this->service()->history($this->school, $this->admin, $this->asha->id)['memberships'];
        $this->assertSame([$second->id, $first->id], $history->pluck('id')->all(), 'current first, both periods kept');

        // Stale, already-ended and wrong-type reasons are refused.
        try {
            $this->service()->leave($this->school, $this->admin, $this->asha->id, $second->id, CommunityMembershipReason::WITHDREW, 2);
            $this->fail('a stale version is refused');
        } catch (StudentCommunityChangedException) {
        }
        $this->refused(fn () => $this->service()->leave($this->school, $this->admin, $this->asha->id, $first->id, CommunityMembershipReason::WITHDREW, 2), 'NOT_A_MEMBER');
        try {
            $this->service()->leave($this->school, $this->admin, $this->asha->id, $second->id, CommunityMembershipReason::COMPLETED, 1);
            $this->fail('"completed" ends an Activity, never a Club');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        $football = $this->activity('Football', 'FOOT');
        $this->assertSame(CommunityMembershipReason::COMPLETED, $this->leave($this->join($this->asha, $football), CommunityMembershipReason::COMPLETED)->end_reason);
    }

    #[Test]
    public function only_active_students_join_and_inactive_groups_take_no_one(): void
    {
        $chess = $this->club('Chess', 'CHESS');
        $membership = $this->join($this->asha, $chess);
        $this->admin()->setStatus($this->school, $this->admin, StudentGroupType::CLUB, $this->club('Art', 'ART')->id, 'inactive', 1);
        $art = $this->inSchool(fn () => StudentGroup::query()->where('code', 'ART')->firstOrFail());
        $this->refused(fn () => $this->join($this->ravi, $art), 'COMMUNITY_INACTIVE');

        $this->forceStudentStatus($this->asha, 'inactive');
        $this->assertSame([$chess->id], $this->current($this->asha), 'inactivation never ends a membership');
        $this->refused(fn () => $this->join($this->asha, $this->activity('Music', 'MUSIC')), 'STUDENT_NOT_ACTIVE');
        $this->refused(fn () => $this->service()->assignResponsibility($this->school, $this->admin, $this->asha->id, $membership->id, ClubResponsibility::PRESIDENT), 'STUDENT_NOT_ACTIVE');
        $this->forceStudentStatus($this->asha, 'active');
        $this->assertSame(1, $this->inSchool(fn () => StudentGroupMembership::query()->where('student_id', $this->asha->id)->count()), 'reactivation duplicates nothing');

        $this->forceStudentStatus($this->asha, 'inactive');
        $this->leave($membership, CommunityMembershipReason::LEFT_SCHOOL);
        $this->assertSame([], $this->current($this->asha), 'ending stays possible for any Student');
    }

    #[Test]
    public function a_group_with_current_members_cannot_be_deactivated(): void
    {
        $chess = $this->club('Chess', 'CHESS');
        $membership = $this->join($this->asha, $chess);
        $this->refused(fn () => $this->admin()->setStatus($this->school, $this->admin, StudentGroupType::CLUB, $chess->id, 'inactive', 1), 'COMMUNITY_HAS_MEMBERS');
        $this->leave($membership);
        $this->assertSame('inactive', $this->admin()->setStatus($this->school, $this->admin, StudentGroupType::CLUB, $chess->id, 'inactive', 1)->status);
        $this->audited('student_group.deactivated', ['studentGroupId' => $chess->id, 'groupType' => 'club']);
        $this->assertSame('active', $this->admin()->setStatus($this->school, $this->admin, StudentGroupType::CLUB, $chess->id, 'active', 2)->status);

        $football = $this->activity('Football', 'FOOT');
        $this->join($this->ravi, $football);
        $this->refused(fn () => $this->admin()->setStatus($this->school, $this->admin, StudentGroupType::ACTIVITY, $football->id, 'inactive', 1), 'COMMUNITY_HAS_MEMBERS');
    }

    #[Test]
    public function club_responsibilities_are_unique_where_declared_and_end_with_the_membership(): void
    {
        $chess = $this->club('Chess', 'CHESS');
        [$a, $r] = [$this->join($this->asha, $chess), $this->join($this->ravi, $chess)];
        $give = fn (StudentGroupMembership $m, ClubResponsibility $role) => $this->service()->assignResponsibility($this->school, $this->admin, $m->student_id, $m->id, $role);

        $president = $give($a, ClubResponsibility::PRESIDENT);
        $this->refused(fn () => $give($r, ClubResponsibility::PRESIDENT), 'RESPONSIBILITY_TAKEN');
        $this->refused(fn () => $give($a, ClubResponsibility::PRESIDENT), 'RESPONSIBILITY_HELD');
        $give($a, ClubResponsibility::MEMBER_REPRESENTATIVE);
        $give($r, ClubResponsibility::MEMBER_REPRESENTATIVE);
        $secretary = $give($r, ClubResponsibility::SECRETARY);
        $this->audited('student_community.responsibility_assigned', ['studentId' => $this->asha->id, 'responsibility' => 'club_president']);

        // Activities have no responsibilities; a House responsibility is not reachable here.
        $football = $this->join($this->asha, $this->activity('Football', 'FOOT'));
        try {
            $give($football, ClubResponsibility::SECRETARY);
            $this->fail('an Activity has no responsibilities');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('responsibility', $e->errors());
        }

        // Ending one carries its version; leaving ends the rest.
        try {
            $this->service()->endResponsibility($this->school, $this->admin, $this->ravi->id, $secretary->id, 2);
            $this->fail('a stale responsibility version is refused');
        } catch (StudentCommunityChangedException) {
        }
        $this->service()->endResponsibility($this->school, $this->admin, $this->ravi->id, $secretary->id, 1);
        $this->audited('student_community.responsibility_ended', ['studentId' => $this->ravi->id, 'responsibility' => 'club_secretary']);
        $this->leave($a);
        $this->assertSame(0, $this->inSchool(fn () => StudentGroupResponsibility::query()->where('student_id', $this->asha->id)->whereNull('ends_on')->count()));
        $give($r, ClubResponsibility::PRESIDENT);

        // A Student may be House Captain and Club President at once; each service
        // reaches only its own type's responsibilities.
        $red = app(HouseAdministrationService::class)->create($this->school, $this->admin, ['name' => 'Red', 'code' => 'RED']);
        $house = app(StudentHouseService::class);
        $house->assign($this->school, $this->admin, $this->ravi->id, $red->id, null, $house->history($this->school, $this->admin, $this->ravi->id)['version']);
        $captain = $house->assignResponsibility($this->school, $this->admin, $this->ravi->id, HouseResponsibility::HOUSE_CAPTAIN, $house->history($this->school, $this->admin, $this->ravi->id)['version']);
        $this->assertSame(['club_president', 'house_captain', 'member_representative'], $this->inSchool(fn () => StudentGroupResponsibility::query()->where('student_id', $this->ravi->id)->whereNull('ends_on')->orderBy('responsibility')->pluck('responsibility')->map(fn ($r) => $r->value)->all()));
        $clubHistory = $this->service()->history($this->school, $this->admin, $this->ravi->id)['responsibilities'];
        $this->assertTrue($clubHistory->isNotEmpty());
        $this->assertFalse($clubHistory->contains(fn ($x) => $x->responsibility instanceof HouseResponsibility), 'the community history lists Club responsibilities only');
        $houseHistory = $house->history($this->school, $this->admin, $this->ravi->id)['responsibilities'];
        $this->assertSame([$captain->id], $houseHistory->pluck('id')->all(), 'the House history lists House responsibilities only');
        try {
            $this->service()->endResponsibility($this->school, $this->admin, $this->ravi->id, $captain->id, 1);
            $this->fail('a House responsibility is not a Club one');
        } catch (ModelNotFoundException) {
        }
        $this->expectException(ModelNotFoundException::class);
        $house->endResponsibility($this->school, $this->admin, $this->ravi->id, $president->id, 1);
    }

    #[Test]
    public function campus_transfer_and_status_changes_never_change_communities(): void
    {
        $chess = $this->join($this->asha, $this->club('Chess', 'CHESS'));
        $football = $this->join($this->asha, $this->activity('Football', 'FOOT'));
        $this->service()->assignResponsibility($this->school, $this->admin, $this->asha->id, $chess->id, ClubResponsibility::PRESIDENT);

        $scope = app(CampusScopeResolver::class)->campusScope($this->admin, 'enrollments.manage', $this->school);
        app(CampusStudentTransferService::class)->transfer($this->school, $scope, $this->asha->id, $this->earlyA->id, '7', $this->admin);
        $asha = fn () => $this->inSchool(fn () => Student::query()->findOrFail($this->asha->id));
        app(StudentService::class)->changeStatus($asha(), 'inactive', 'left_school', $this->admin, $this->freshProof($this->admin));
        app(StudentService::class)->changeStatus($asha(), 'active', 'returned_to_school', $this->admin, $this->freshProof($this->admin));

        $rows = $this->inSchool(fn () => StudentGroupMembership::query()->where('student_id', $this->asha->id)->orderBy('id')->get());
        $this->assertSame([$chess->id, $football->id], $rows->pluck('id')->all());
        $this->assertSame([[null, 1], [null, 1]], $rows->map(fn ($m) => [$m->ends_on, $m->version])->all(), 'never rewritten');
        $this->assertSame(1, $this->inSchool(fn () => StudentGroupResponsibility::query()->whereNull('ends_on')->count()));
    }

    #[Test]
    public function the_authorization_matrix_allows_community_staff_and_refuses_everyone_else(): void
    {
        $chess = $this->club('Chess', 'CHESS');
        $football = $this->activity('Football', 'FOOT');
        $this->join($this->asha, $chess);
        $this->join($this->ravi, $chess);
        $this->join($this->ravi, $football);
        $page = "/app/students/{$this->asha->id}/communities";

        foreach (['school_admin', 'principal'] as $key) {
            $user = $this->role($key);
            $this->assertSame(['house', 'club', 'activity'], array_column($this->props('/app/students/communities', $user)['areas'], 'type'));
            $clubs = $this->props('/app/students/clubs', $user);
            $this->assertSame(['club', 'whole_school', [2], true], [$clubs['type']['value'], $clubs['countsScope'], array_column($clubs['groups'], 'members'), $clubs['canManage']]);
            $this->assertSame([1], array_column($this->props('/app/students/activities', $user)['groups'], 'members'));
            $this->assertTrue($this->props($page, $user)['canAssign']);
        }

        // A Campus principal: Campus-only counts, no School-wide history, no writes.
        $campus = $this->role('principal', $this->main->id);
        $clubs = $this->props('/app/students/clubs', $campus);
        $this->assertSame(['campus', [1], false], [$clubs['countsScope'], array_column($clubs['groups'], 'members'), $clubs['canManage']], 'the School-wide size never leaks through a count');
        $this->assertSame([0], array_column($this->props('/app/students/activities', $campus)['groups'], 'members'));
        $this->as($campus)->get($page)->assertForbidden();
        $this->as($campus)->post('/app/students/clubs', ['name' => 'Art', 'code' => 'ART'])->assertForbidden();
        $this->as($campus)->post($page, ['group_id' => $football->id])->assertForbidden();

        // Teacher and operational roles: nothing.
        foreach (['teacher', 'librarian'] as $key) {
            $user = $this->role($key);
            foreach (['/app/students/communities', '/app/students/clubs', '/app/students/activities', $page, '/app/students/reports/students.clubs', '/app/students/reports/students.activities'] as $url) {
                $this->as($user)->get($url)->assertForbidden();
            }
        }

        // The House and community keys never open each other.
        $housesOnly = $this->holder(['students.view', 'students.houses.view']);
        $this->assertSame(['house'], array_column($this->props('/app/students/communities', $housesOnly)['areas'], 'type'));
        $this->as($housesOnly)->get('/app/students/clubs')->assertForbidden();
        $this->as($housesOnly)->get($page)->assertForbidden();
        $communitiesOnly = $this->holder(['students.view', 'students.communities.view']);
        $this->assertSame(['club', 'activity'], array_column($this->props('/app/students/communities', $communitiesOnly)['areas'], 'type'));
        $this->as($communitiesOnly)->get('/app/students/houses')->assertForbidden();
        $this->assertNull($this->props($page, $communitiesOnly)['house'], 'no House section without House view');
        $this->assertFalse($this->props($page, $communitiesOnly)['canAssign']);
        $this->as($communitiesOnly)->post($page, ['group_id' => $football->id])->assertForbidden();

        // Assign without manage: memberships only.
        $assigner = $this->holder(['students.view', 'students.communities.view', 'students.communities.assign']);
        $this->as($assigner)->post('/app/students/clubs', ['name' => 'Art', 'code' => 'ART'])->assertForbidden();
        $this->as($assigner)->post($page, ['group_id' => $football->id])->assertRedirect($page);
        $this->assertContains($football->id, $this->current($this->asha));

        // Community view without students.view: the Student is unknown.
        $this->as($this->holder(['students.communities.view']))->get($page)->assertNotFound();

        // Authority is re-read in the service, not only at the route.
        $this->expectException(AuthorizationException::class);
        $this->service()->join($this->school, $communitiesOnly, $this->asha->id, $football->id);
    }

    #[Test]
    public function another_school_never_sees_or_touches_these_communities(): void
    {
        $chess = $this->club('Chess', 'CHESS');
        $membership = $this->join($this->asha, $chess);
        [$otherAdmin, $other] = $this->createSchoolAdmin();
        $foreign = $this->admin()->create($other, $otherAdmin, StudentGroupType::CLUB, ['name' => 'Chess', 'code' => 'CHESS']);
        $this->assertNotSame($chess->id, $foreign->id, 'the same name and code in another School never collide');

        $this->as($otherAdmin, $other);
        $this->get("/app/students/{$this->asha->id}/communities")->assertNotFound();
        $this->post("/app/students/{$this->asha->id}/communities", ['group_id' => $foreign->id])->assertNotFound();
        $this->post("/app/students/{$this->asha->id}/communities/{$membership->id}/end", ['reason' => 'withdrew', 'version' => 1])->assertNotFound();
        $this->put("/app/students/clubs/{$chess->id}", ['name' => 'X', 'code' => 'X', 'version' => 1])->assertNotFound();
        $this->assertSame([0], array_column($this->get('/app/students/clubs')->viewData('page')['props']['groups'], 'members'));
        $this->assertSame([], $this->get('/app/students/reports/students.clubs')->viewData('page')['props']['rows']['data']);

        $this->expectException(ModelNotFoundException::class);
        $this->service()->join($this->school, $this->admin, $this->ravi->id, $foreign->id);
    }

    #[Test]
    public function the_profile_card_lists_current_communities_within_the_viewers_scope(): void
    {
        $chess = $this->join($this->asha, $this->club('Chess', 'CHESS'));
        $this->join($this->asha, $this->activity('Football', 'FOOT', ActivityCategory::SPORTS));
        $this->service()->assignResponsibility($this->school, $this->admin, $this->asha->id, $chess->id, ClubResponsibility::PRESIDENT);
        foreach (range(1, 5) as $i) {
            $this->join($this->asha, $this->club("Club {$i}", "C{$i}"));
        }

        $card = $this->props("/app/students/{$this->asha->id}", $this->admin)['sections']['communities'];
        $this->assertCount(StudentCommunityService::CARD_LIMIT, $card['clubs']);
        $this->assertSame(6 - StudentCommunityService::CARD_LIMIT, $card['moreClubs'], 'a long list stays short');
        $this->assertSame([['name' => 'Football', 'category' => 'Sports']], $card['activities']);
        $this->assertContains(['name' => 'Chess', 'responsibilities' => ['President']], $card['clubs']);
        $this->assertSame("/app/students/{$this->asha->id}/communities", $card['link']);

        $campus = $this->role('principal', $this->main->id);
        $scope = app(CampusScopeResolver::class)->campusScope($campus, StudentCommunityService::VIEW, $this->school);
        $this->assertNotNull($this->service()->summary($this->school, $scope, $this->asha));
        $this->assertNull($this->service()->summary($this->school, $scope, $this->ravi), 'out of scope is absent');
        $this->assertArrayNotHasKey('communities', $this->props("/app/students/{$this->asha->id}", $this->holder(['students.view']))['sections'], 'absent, never hidden');
    }

    #[Test]
    public function a_membership_or_responsibility_never_changes_anyones_capabilities(): void
    {
        $resolver = app(CapabilityResolver::class);
        $actors = [$this->admin, $this->role('teacher'), $this->role('principal', $this->main->id)];
        $snapshot = function () use ($resolver, $actors): array {
            return array_map(function (User $u) use ($resolver): array {
                $resolver->forgetCache($u, $this->school);

                return $resolver->schoolCapabilities($u, $this->school);
            }, $actors);
        };
        $before = $snapshot();

        $chess = $this->join($this->asha, $this->club('Chess', 'CHESS'));
        $this->join($this->asha, $this->activity('Football', 'FOOT'));
        $this->service()->assignResponsibility($this->school, $this->admin, $this->asha->id, $chess->id, ClubResponsibility::PRESIDENT);

        $this->assertSame($before, $snapshot());
        $this->as($actors[1])->get('/app/students/clubs')->assertForbidden();
    }
}
