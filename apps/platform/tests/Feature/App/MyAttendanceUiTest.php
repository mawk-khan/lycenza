<?php

namespace Tests\Feature\App;

use App\Domain\Attendance\Infrastructure\AttendanceSession;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeacherAttendanceFixtures;
use Tests\Concerns\CreatesTeacherDeliveryFixtures;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Feature\Attendance\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * TCH.4 -- "My Attendance": the owned teacher pages reuse the Attendance
 * Index/Take/Show pages under /app/my-attendance with only the teacher's
 * registers, classes and rosters; the dashboard link follows the
 * capability, never the role key.
 */
class MyAttendanceUiTest extends TestCase
{
    use CreatesAttendanceFixtures, CreatesTeacherAttendanceFixtures, CreatesTeacherDeliveryFixtures, CreatesTeachingAssignmentFixtures;

    private function actor(array $w, User $user): static
    {
        return $this->actingAs($user)->withHeader('X-School-Id', $w['school']->id);
    }

    #[Test]
    public function a_teacher_sees_only_their_registers_classes_and_roster(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee, '2026-06-01');
        $mine = $this->adminRegister($w, $w['entry'], self::MONDAY);
        $this->adminRegister($w, $w['entryB'], self::MONDAY);

        $this->actor($w, $user)->get('/app/my-attendance')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/Attendance/Index')
                ->where('baseUrl', '/app/my-attendance')
                ->where('heading', 'My Attendance')
                ->where('canManage', true)
                ->has('sessions.data', 1)
                ->where('sessions.data.0.id', $mine->id));

        $this->actor($w, $user)->get('/app/my-attendance/take?attendance_date=2026-09-07&timetable_entry_id='.$w['entryB']->id)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('App/Attendance/Take')
                ->has('classes', 1)
                ->where('classes.0.timetableEntryId', $w['entry']->id)
                ->where('selectedTimetableEntryId', null)
                ->has('roster', 0));

        $this->actor($w, $user)->get('/app/my-attendance/take?attendance_date=2026-09-07&timetable_entry_id='.$w['entry']->id)
            ->assertInertia(fn (AssertableInertia $page) => $page->has('roster', 2));

        $this->actor($w, $user)->get("/app/my-attendance/{$mine->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('App/Attendance/Show')->where('baseUrl', '/app/my-attendance')->has('session.records', 2));
    }

    #[Test]
    public function a_teacher_takes_and_corrects_through_the_pages_and_unowned_is_not_found(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$user, $employee] = $this->teacher($w);
        $this->own($w, $employee, '2026-06-01');
        $theirs = $this->adminRegister($w, $w['entryB'], self::MONDAY);

        $this->actor($w, $user)->post('/app/my-attendance', [
            'timetable_entry_id' => $w['entry']->id, 'attendance_date' => self::MONDAY, 'records' => $this->allPresent($w['studentsA']),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $session = $this->inSchool($w['school'], fn () => AttendanceSession::query()->where('section_id', $w['section']->id)->firstOrFail());
        $record = $this->inSchool($w['school'], fn () => $session->records()->firstOrFail());
        $this->actor($w, $user)->post("/app/my-attendance/records/{$record->id}/correct", ['expected_status' => 'present', 'new_status' => 'late'])
            ->assertRedirect("/app/my-attendance/{$session->id}");

        $this->actor($w, $user)->get("/app/my-attendance/{$theirs->id}")->assertNotFound();
        $this->actor($w, $user)->post('/app/my-attendance', [
            'timetable_entry_id' => $w['entryB']->id, 'attendance_date' => '2026-09-07', 'records' => $this->allPresent($w['studentsB']),
        ])->assertNotFound();
    }

    #[Test]
    public function the_navigation_follows_the_capabilities_and_admin_pages_stay_closed(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$user] = $this->teacher($w);
        [$noRole] = $this->teacher($w, roleKey: null);

        $this->actor($w, $user)->get('/app')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('nav.canUseMyAttendance', true)
            ->where('nav.canUseMyCurriculumDelivery', true)
            ->where('nav', fn ($nav) => collect($nav)->filter(fn ($v) => $v === true)->keys()->sort()->values()->all() === ['canUseMyAttendance', 'canUseMyCurriculumDelivery', 'canUseMyLearningContent']));

        $this->actor($w, $noRole)->get('/app/my-attendance')->assertForbidden();
        foreach (['/app/attendance', '/app/attendance/take', '/app/students', '/app/teaching-assignments', '/app/timetable-schedule'] as $page) {
            $this->actor($w, $user)->get($page)->assertForbidden();
        }
    }

    #[Test]
    public function a_capability_holder_who_is_not_an_eligible_employee_sees_an_empty_list_and_cannot_take(): void
    {
        $w = $this->teacherAttendanceWorld();
        [$user] = $this->teacher($w, linked: false);

        $this->actor($w, $user)->get('/app/my-attendance')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canManage', false)->has('sessions.data', 0));
        $this->actor($w, $user)->get('/app/my-attendance/take')->assertForbidden();
    }
}
