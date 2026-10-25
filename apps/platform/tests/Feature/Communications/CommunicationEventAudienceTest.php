<?php

namespace Tests\Feature\Communications;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Testing\LocalCatalogueFixtures;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Feature\Communications\Concerns\CreatesCommunicationEventFixtures;
use Tests\TestCase;

/**
 * ORG.9D-3 (ADR 0076 §75): who sees which published Event, decided for the
 * VIEWER at read time -- the staff calendar (School-wide; Staff only while
 * current staff) and the Guardian portal (School-wide; the Grade/Section
 * their eligible Students are in now; never Staff). Former staff, former
 * Guardians, other Schools, drafts and never-published Events see nothing
 * they should not; a cancelled published Event stays visible, marked.
 */
class CommunicationEventAudienceTest extends TestCase
{
    use CreatesCommunicationEventFixtures, CreatesGuardianPortalFixtures, CreatesTenancyFixtures;

    private School $school;

    private User $preparer;

    private User $approver;

    private AcademicYear $year;

    private Section $five;

    private Section $six;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-01T06:00:00Z'));
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $this->year = $this->createAcademicYear($this->school, ['status' => 'active', 'starts_on' => '2026-06-01', 'ends_on' => '2027-03-31']);
        $campus = $this->createCampus($this->school);
        $this->five = $this->createSection($this->year, $campus, $this->createGradeLevel($this->school, ['name' => 'Grade 5', 'code' => 'G5']), ['code' => '5A', 'status' => 'active']);
        $this->six = $this->createSection($this->year, $campus, $this->createGradeLevel($this->school, ['name' => 'Grade 6', 'code' => 'G6']), ['code' => '6A', 'status' => 'active']);
        $this->preparer = $this->createUserWithCapabilities($this->school, ['communications.view', 'communications.announce']);
        $this->approver = $this->createUserWithCapabilities($this->school, ['communications.view', 'communications.approve']);

        $this->publishedEvent($this->school, $this->preparer, $this->approver, ['title' => 'Open day']);
        $this->publishedEvent($this->school, $this->preparer, $this->approver, ['title' => 'Staff meeting', 'audience_type' => 'staff']);
        $this->publishedEvent($this->school, $this->preparer, $this->approver, ['title' => 'Grade 5 trip', 'audience_type' => 'grade', 'grade_level_id' => $this->five->grade_level_id]);
        $this->publishedEvent($this->school, $this->preparer, $this->approver, ['title' => 'Section 6A play', 'audience_type' => 'section', 'section_id' => $this->six->id]);
        $this->draftEvent($this->school, $this->preparer, ['title' => 'Draft only']);
        $this->events()->cancel($this->draftEvent($this->school, $this->preparer, ['title' => 'Never published']), $this->preparer);
        $fair = $this->publishedEvent($this->school, $this->preparer, $this->approver, ['title' => 'Book fair']);
        $this->events()->cancel($fair, $this->preparer);
    }

    /** A User who can open the staff calendar, optionally as CURRENT (or former) staff. */
    private function staffViewer(?string $endsOn = null, ?School $school = null, bool $employee = true): User
    {
        $school ??= $this->school;
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        LocalCatalogueFixtures::grantRole($membership, $this->createFixtureRole(['communications.events.view']));
        if ($employee) {
            $record = $this->createEmployee($school, ['user_id' => $user->id, 'record_status' => 'active']);
            $this->createEmploymentRecord($record, ['status' => $endsOn === null ? 'active' : 'separated', 'starts_on' => '2025-01-01', 'ends_on' => $endsOn]);
        }

        return $user;
    }

    /** A portal Guardian (legal guardian, active link) of a child placed in `$section` now. */
    private function guardianOf(Section $section, ?User $user = null): array
    {
        $g = $this->portalGuardian($this->school, $user, $user === null ? null : SchoolMembership::query()->where('user_id', $user->id)->where('school_id', $this->school->id)->first());
        $this->createStudentEnrollment($g['student'], $section, ['starts_on' => '2026-06-01', 'status' => 'active']);

        return $g;
    }

    /** @return array<string, bool> title => cancelled */
    private function staffCalendar(User $viewer, string $when = 'upcoming'): array
    {
        $props = $this->signInTo($viewer, $this->school)->get('/app/communications/calendar?when='.$when)->assertOk()->viewData('page')['props'];

        return collect($props['events'])->mapWithKeys(fn ($e) => [$e['title'] => $e['cancelled']])->all();
    }

    /** @return array<string, bool> */
    private function portalCalendar(User $viewer): array
    {
        $props = $this->signInTo($viewer, $this->school)->get('/app/portal/calendar')->assertOk()->viewData('page')['props'];

        return collect($props['events'])->mapWithKeys(fn ($e) => [$e['title'] => $e['cancelled']])->all();
    }

    #[Test]
    public function current_staff_see_school_wide_and_staff_events_and_former_or_non_staff_do_not_see_staff_events(): void
    {
        $this->assertEquals(['Open day' => false, 'Staff meeting' => false, 'Book fair' => true], $this->staffCalendar($this->staffViewer()));
        $this->assertEquals(['Open day' => false, 'Book fair' => true], $this->staffCalendar($this->staffViewer('2026-09-30')), 'A leaver stops seeing Staff events.');
        $this->assertEquals(['Open day' => false, 'Book fair' => true], $this->staffCalendar($this->staffViewer(employee: false)), 'A role or capability is not "staff".');

        $elsewhere = $this->createSchool();
        $foreign = $this->staffViewer(school: $elsewhere);
        $props = $this->signInTo($foreign, $elsewhere)->get('/app/communications/calendar')->assertOk()->viewData('page')['props'];
        $this->assertSame([], $props['events'], 'Another School sees none of these Events.');
    }

    #[Test]
    public function guardians_see_school_wide_and_their_childrens_grade_or_section_events_never_staff_events(): void
    {
        $five = $this->guardianOf($this->five);
        $six = $this->guardianOf($this->six);
        $this->assertEquals(['Open day' => false, 'Grade 5 trip' => false, 'Book fair' => true], $this->portalCalendar($five['user']));
        $this->assertEquals(['Open day' => false, 'Section 6A play' => false, 'Book fair' => true], $this->portalCalendar($six['user']));

        // A Guardian-only account has no staff calendar at all.
        $this->signInTo($five['user'], $this->school)->get('/app/communications/calendar')->assertForbidden();

        // A child moving to another Section moves the Guardian's view with them: placement is read now.
        [$campus, $grade] = $this->inSchool($this->school, fn () => [$this->five->campus()->first(), $this->five->gradeLevel()->first()]);
        $fiveB = $this->createSection($this->year, $campus, $grade, ['code' => '5B', 'status' => 'active']);
        $this->publishedEvent($this->school, $this->preparer, $this->approver, ['title' => 'Section 5B assembly', 'audience_type' => 'section', 'section_id' => $fiveB->id]);
        $this->assertArrayNotHasKey('Section 5B assembly', $this->portalCalendar($five['user']));
        $this->travelTo(CarbonImmutable::parse('2026-10-02T06:00:00Z'));
        $this->inSchool($this->school, fn () => app(StudentEnrollmentService::class)->transferPlacement(
            StudentEnrollment::query()->where('student_id', $five['student']->id)->where('status', 'active')->firstOrFail(), $fiveB, 'MOVE-1', '2026-10-02'));
        $this->assertEquals(['Open day' => false, 'Grade 5 trip' => false, 'Section 5B assembly' => false, 'Book fair' => true], $this->portalCalendar($five['user']));
    }

    #[Test]
    public function a_staff_member_who_is_a_guardian_sees_each_persona_on_its_own_surface(): void
    {
        $teacher = $this->staffViewer();
        $this->guardianOf($this->five, $teacher);

        $this->assertEquals(['Open day' => false, 'Staff meeting' => false, 'Book fair' => true], $this->staffCalendar($teacher));
        $this->assertEquals(['Open day' => false, 'Grade 5 trip' => false, 'Book fair' => true], $this->portalCalendar($teacher), 'Never a Staff event in the portal.');
    }

    #[Test]
    public function a_former_guardian_loses_the_portal_calendar_and_past_events_move_to_the_past_list(): void
    {
        $former = $this->guardianOf($this->five);
        app(AccountLinkService::class)->unlinkGuardian($this->school, $former['guardian'], $this->portalAdmin($this->school));
        $this->signInTo($former['user'], $this->school)->get('/app/portal/calendar')->assertForbidden();

        $teacher = $this->staffViewer();
        $this->travelTo(CarbonImmutable::parse('2026-11-21T00:00:00Z')); // after 20 Nov in Kolkata
        $this->assertSame([], $this->staffCalendar($teacher));
        $this->assertEquals(['Open day' => false, 'Staff meeting' => false, 'Book fair' => true], $this->staffCalendar($teacher, 'past'));
    }

    private function inSchool(School $school, callable $fn): mixed
    {
        return app(TenantContext::class)->withSchool($school, $fn);
    }
}
