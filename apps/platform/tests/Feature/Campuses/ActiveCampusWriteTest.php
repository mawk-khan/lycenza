<?php

namespace Tests\Feature\Campuses;

use App\Domain\AcademicStructure\Application\RoomAdministrationService;
use App\Domain\AcademicStructure\Application\SectionAdministrationService;
use App\Domain\AcademicStructure\Application\SubjectOfferingService;
use App\Domain\AcademicStructure\Infrastructure\Room;
use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Admissions\Application\AdmissionApplicationService;
use App\Domain\Admissions\Application\Exceptions\CrossSchoolAdmissionReferenceException;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Campuses\Application\ActiveCampus;
use App\Domain\HR\Application\EmployeeAssignmentService;
use App\Domain\HR\Application\Exceptions\AssignmentCampusMismatchException;
use App\Domain\HR\Application\Exceptions\AssignmentInactiveCampusException;
use App\Domain\HR\Infrastructure\EmployeeAssignment;
use App\Domain\Timetable\Application\Exceptions\CampusNotAvailableException;
use App\Domain\Timetable\Application\Exceptions\RoomCampusMismatchException;
use App\Domain\Timetable\Application\TimetableScheduleService;
use App\Domain\Timetable\Infrastructure\TimetableEntry;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Timetable\Concerns\CreatesTimetableFixtures;
use Tests\TestCase;
use Throwable;

/**
 * ORG.7D-0 R1/R5 (ADR 0076 §36): the authoritative writes that place new
 * work on a Campus -- Section, Subject Offering, Timetable entry, admission
 * application, employee assignment and (ORG.7D-1 D1-1, §37) Room -- need an
 * ACTIVE Campus of the same School; a Timetable Room must be on the entry's
 * Campus; (ORG.7D-1 D1-2) a Section or Subject Offering on an inactive
 * Campus is never reactivated. Records already on a Campus that later
 * becomes inactive are kept untouched. ActiveCampus is data validity only:
 * authorization is unchanged.
 */
class ActiveCampusWriteTest extends TestCase
{
    use CreatesTimetableFixtures;

    private School $school;

    private Campus $active;

    private Campus $inactive;

    private Campus $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->active = $this->createCampus($this->school);
        $this->inactive = $this->createCampus($this->school, ['status' => 'inactive']);
        $this->foreign = $this->createCampus($this->createSchool());
    }

    private function deactivate(Campus $campus): void
    {
        app(TenantContext::class)->withSchool($this->school, fn () => $campus->forceFill(['status' => 'inactive'])->save());
    }

    private function refusal(callable $write): Throwable
    {
        try {
            $write();
        } catch (Throwable $e) {
            return $e;
        }
        $this->fail('The write was accepted.');
    }

    private function assertCampusFieldError(Throwable $e): void
    {
        $this->assertInstanceOf(ValidationException::class, $e);
        $this->assertSame([ActiveCampus::MESSAGE], $e->errors()['campus_id']);
    }

    private function rows(string $model, string $campusId): int
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => $model::query()->where('campus_id', $campusId)->count());
    }

    #[Test]
    public function the_validator_accepts_only_an_active_campus_of_the_school(): void
    {
        $check = app(ActiveCampus::class);
        app(TenantContext::class)->withSchool($this->school, function () use ($check): void {
            $this->assertSame($this->active->id, $check->find($this->school, $this->active->id)?->id);
            $this->assertNull($check->find($this->school, $this->inactive->id));
            $this->assertNull($check->find($this->school, $this->foreign->id));
            $this->assertNull($check->find($this->school, 'not-a-uuid'));
            $this->assertNull($check->find($this->school, null));
        });
    }

    #[Test]
    public function a_section_needs_an_active_campus(): void
    {
        $actor = $this->createUserWithCapabilities($this->school, ['academics.structure.manage']);
        $year = $this->createAcademicYear($this->school);
        $grade = $this->createGradeLevel($this->school);
        $create = fn (Campus $campus, string $code) => app(SectionAdministrationService::class)
            ->create($this->school, $year->id, ['campus_id' => $campus->id, 'grade_level_id' => $grade->id, 'name' => $code, 'code' => $code], $actor);

        $this->assertSame($this->active->id, $create($this->active, 'A')->campus_id);
        $this->assertCampusFieldError($this->refusal(fn () => $create($this->inactive, 'B')));
        $this->assertInstanceOf(ModelNotFoundException::class, $this->refusal(fn () => $create($this->foreign, 'C')));
        $this->assertSame(0, $this->rows(Section::class, $this->inactive->id));

        // A Section already on a Campus that becomes inactive is kept and still maintainable.
        $this->deactivate($this->active);
        $this->assertSame(1, $this->rows(Section::class, $this->active->id));
        $section = app(TenantContext::class)->withSchool($this->school, fn () => Section::query()->where('campus_id', $this->active->id)->firstOrFail());
        $this->assertSame('Renamed', app(SectionAdministrationService::class)->update($this->school, $section->id, ['name' => 'Renamed'], $actor)->name);
    }

    #[Test]
    public function a_subject_offering_needs_an_active_campus(): void
    {
        $actor = $this->createUserWithCapabilities($this->school, ['academics.subjects.manage']);
        $year = $this->createAcademicYear($this->school);
        $grade = $this->createGradeLevel($this->school);
        $create = fn (Campus $campus) => app(SubjectOfferingService::class)
            ->create($this->school, $year->id, ['campus_id' => $campus->id, 'grade_level_id' => $grade->id, 'subject_id' => $this->createSubject($this->school)->id], $actor);

        $this->assertSame($this->active->id, $create($this->active)->campus_id);
        $this->assertCampusFieldError($this->refusal(fn () => $create($this->inactive)));
        $this->assertInstanceOf(ModelNotFoundException::class, $this->refusal(fn () => $create($this->foreign)));
        $this->assertSame(0, $this->rows(SubjectOffering::class, $this->inactive->id));

        $this->deactivate($this->active);
        $this->assertSame(1, $this->rows(SubjectOffering::class, $this->active->id));
    }

    #[Test]
    public function a_room_needs_an_active_campus(): void
    {
        $actor = $this->createUserWithCapabilities($this->school, ['academics.structure.manage']);
        $create = fn (Campus $campus, string $code) => app(RoomAdministrationService::class)
            ->create($this->school, $campus->id, ['name' => $code, 'code' => $code], $actor);

        $this->assertSame($this->active->id, $create($this->active, 'R1')->campus_id);
        $this->assertCampusFieldError($this->refusal(fn () => $create($this->inactive, 'R2')));
        $this->assertInstanceOf(ModelNotFoundException::class, $this->refusal(fn () => $create($this->foreign, 'R3')));
        $this->assertSame(0, $this->rows(Room::class, $this->inactive->id));

        // A Room already on a Campus that becomes inactive is kept and still maintainable.
        $this->deactivate($this->active);
        $room = app(TenantContext::class)->withSchool($this->school, fn () => Room::query()->where('campus_id', $this->active->id)->firstOrFail());
        $this->assertSame('Renamed', app(RoomAdministrationService::class)->update($this->school, $room->id, ['name' => 'Renamed'], $actor)->name);
        $this->assertSame(1, $this->rows(Room::class, $this->active->id));
    }

    #[Test]
    public function a_section_on_an_inactive_campus_is_never_reactivated(): void
    {
        $actor = $this->createUserWithCapabilities($this->school, ['academics.structure.manage']);
        $year = $this->createAcademicYear($this->school);
        $grade = $this->createGradeLevel($this->school);
        $service = app(SectionAdministrationService::class);
        $onActive = $service->create($this->school, $year->id, ['campus_id' => $this->active->id, 'grade_level_id' => $grade->id, 'name' => 'A', 'code' => 'A'], $actor);
        $moving = $service->create($this->school, $year->id, ['campus_id' => $this->active->id, 'grade_level_id' => $grade->id, 'name' => 'B', 'code' => 'B'], $actor);

        // Active Campus: deactivate and reactivate as before.
        $this->assertSame('inactive', $service->update($this->school, $onActive->id, ['status' => 'inactive'], $actor)->status);
        $this->assertSame('active', $service->update($this->school, $onActive->id, ['status' => 'active'], $actor)->status);

        // Inactive Campus: deactivation works, reactivation is refused and nothing changes.
        $service->update($this->school, $moving->id, ['status' => 'inactive'], $actor);
        $this->deactivate($this->active);
        $e = $this->refusal(fn () => $service->update($this->school, $moving->id, ['status' => 'active', 'name' => 'Changed'], $actor));
        $this->assertInstanceOf(ValidationException::class, $e);
        $this->assertSame([SectionAdministrationService::INACTIVE_CAMPUS], $e->errors()['status']);
        $kept = app(TenantContext::class)->withSchool($this->school, fn () => Section::query()->findOrFail($moving->id));
        $this->assertSame(['inactive', 'B', $this->active->id], [$kept->status, $kept->name, $kept->campus_id], 'no partial write, no Campus change');
        $this->assertSame('inactive', app(TenantContext::class)->withSchool($this->school, fn () => Campus::query()->findOrFail($this->active->id)->status), 'the Campus is never activated as a side effect');

        // A Section that is still active stays maintainable (re-sending its status is not a reactivation).
        $this->assertSame('Renamed', $service->update($this->school, $onActive->id, ['name' => 'Renamed', 'status' => 'active'], $actor)->name);
        $this->assertSame('inactive', $service->update($this->school, $onActive->id, ['status' => 'inactive'], $actor)->status);
    }

    #[Test]
    public function a_subject_offering_on_an_inactive_campus_is_never_reactivated(): void
    {
        $actor = $this->createUserWithCapabilities($this->school, ['academics.subjects.manage']);
        $year = $this->createAcademicYear($this->school);
        $grade = $this->createGradeLevel($this->school);
        $service = app(SubjectOfferingService::class);
        $create = fn () => $service->create($this->school, $year->id, ['campus_id' => $this->active->id, 'grade_level_id' => $grade->id, 'subject_id' => $this->createSubject($this->school)->id], $actor);
        $onActive = $create();
        $moving = $create();

        $this->assertSame('inactive', $service->updateFromInput($this->school, $onActive->id, ['status' => 'inactive'], $actor)->status);
        $this->assertSame('active', $service->updateFromInput($this->school, $onActive->id, ['status' => 'active'], $actor)->status);

        $service->updateFromInput($this->school, $moving->id, ['status' => 'inactive'], $actor);
        $this->deactivate($this->active);
        $e = $this->refusal(fn () => $service->updateFromInput($this->school, $moving->id, ['status' => 'active', 'sequence' => 9], $actor));
        $this->assertInstanceOf(ValidationException::class, $e);
        $this->assertSame([SubjectOfferingService::INACTIVE_CAMPUS], $e->errors()['status']);
        $kept = app(TenantContext::class)->withSchool($this->school, fn () => SubjectOffering::query()->findOrFail($moving->id));
        $this->assertSame(['inactive', $this->active->id], [$kept->status, $kept->campus_id]);
        $this->assertNotSame(9, $kept->sequence, 'no partial write');

        $this->assertSame(3, $service->updateFromInput($this->school, $onActive->id, ['sequence' => 3, 'status' => 'active'], $actor)->sequence);
        $this->assertSame('inactive', $service->updateFromInput($this->school, $onActive->id, ['status' => 'inactive'], $actor)->status);
    }

    /** @return array{0: User, 1: callable} an actor and a create(Campus $offeringCampus, ?Room $room, int $day) closure */
    private function timetable(): array
    {
        $actor = $this->fullTimetableActor($this->school);
        $year = $this->createAcademicYear($this->school);
        $grade = $this->createGradeLevel($this->school);
        $teacher = $this->createEmployee($this->school, ['record_status' => 'active']);
        $period = $this->createTimetablePeriod($this->school, ['start_time' => '09:00:00', 'end_time' => '09:45:00']);
        $create = function (Campus $campus, $room, int $day) use ($actor, $year, $grade, $teacher, $period) {
            $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($this->school), ['is_required' => true, 'status' => 'active']);
            $section = $this->createSection($year, $campus, $grade, ['status' => 'active', 'code' => 'S'.$day]);

            return app(TimetableScheduleService::class)->create($this->school, $offering, $section, $teacher, $room, $period, $day, $actor);
        };

        return [$actor, $create];
    }

    #[Test]
    public function a_timetable_room_must_be_on_the_entrys_campus(): void
    {
        [, $create] = $this->timetable();
        $other = $this->createCampus($this->school);

        $this->assertNotNull($create($this->active, $this->createRoom($this->active, ['status' => 'active']), 1)->room_id);
        $this->assertNull($create($this->active, null, 2)->room_id, 'a Room-less entry is unaffected');
        $this->assertInstanceOf(RoomCampusMismatchException::class, $this->refusal(fn () => $create($this->active, $this->createRoom($other, ['status' => 'active']), 3)));
        $this->assertInstanceOf(RoomCampusMismatchException::class, $this->refusal(fn () => $create($this->active, $this->createRoom($this->foreign, ['status' => 'active']), 4)));
        $this->assertSame(2, $this->rows(TimetableEntry::class, $this->active->id));
    }

    #[Test]
    public function nothing_new_is_scheduled_on_an_inactive_campus_and_existing_entries_are_kept(): void
    {
        [$actor, $create] = $this->timetable();
        $entry = $create($this->active, null, 1);
        $this->deactivate($this->active);

        // A new entry, an update and a re-activation are refused; deactivation still works.
        $this->assertInstanceOf(CampusNotAvailableException::class, $this->refusal(fn () => $create($this->active, null, 2)));
        $service = app(TimetableScheduleService::class);
        $parents = app(TenantContext::class)->withSchool($this->school, fn () => [$entry->subjectOffering()->firstOrFail(), $entry->section()->firstOrFail(), $entry->teacher()->firstOrFail(), $entry->period()->firstOrFail()]);
        $this->assertInstanceOf(CampusNotAvailableException::class, $this->refusal(fn () => $service->update($entry, $parents[0], $parents[1], $parents[2], null, $parents[3], 5, $actor)));
        $this->assertSame('inactive', $service->deactivate($entry, $actor)->status);
        $this->assertInstanceOf(CampusNotAvailableException::class, $this->refusal(fn () => $service->activate($entry, $actor)));

        $kept = app(TenantContext::class)->withSchool($this->school, fn () => TimetableEntry::query()->findOrFail($entry->id));
        $this->assertSame([$this->active->id, 1], [$kept->campus_id, $kept->day_of_week], 'history is never rewritten');
    }

    #[Test]
    public function an_admission_application_needs_an_active_campus(): void
    {
        $applicant = $this->createApplicant($this->school);
        $year = $this->createAcademicYear($this->school);
        $grade = $this->createGradeLevel($this->school);
        $service = app(AdmissionApplicationService::class);

        $this->assertCampusFieldError($this->refusal(fn () => $service->create($applicant, $year, $this->inactive, $grade)));
        $this->assertInstanceOf(CrossSchoolAdmissionReferenceException::class, $this->refusal(fn () => $service->create($applicant, $year, $this->foreign, $grade)));
        $this->assertSame($this->active->id, $service->create($applicant, $year, $this->active, $grade)->campus_id);

        $this->deactivate($this->active);
        $this->assertSame(1, $this->rows(AdmissionApplication::class, $this->active->id));
    }

    #[Test]
    public function an_employee_assignment_needs_an_active_campus(): void
    {
        $actor = $this->createUserWithCapabilities($this->school, ['hr.employees.assignments.manage']);
        $employment = $this->createEmploymentRecord($this->createEmployee($this->school), ['starts_on' => '2026-01-01', 'status' => 'active']);
        $position = $this->createPosition($this->school, ['status' => 'active']);
        $service = app(EmployeeAssignmentService::class);
        $create = fn (?Campus $campus) => $service->create($employment, ['starts_on' => '2026-02-01'], $position, $actor, $campus);

        $this->assertInstanceOf(AssignmentInactiveCampusException::class, $this->refusal(fn () => $create($this->inactive)));
        $this->assertInstanceOf(AssignmentCampusMismatchException::class, $this->refusal(fn () => $create($this->foreign)));
        $assignment = $create($this->active);
        $this->assertNull($create(null)->campus_id, 'an assignment without a Campus is unaffected');

        // An existing assignment on a Campus that becomes inactive is kept and can still be ended.
        $this->deactivate($this->active);
        $this->assertSame('2026-03-01', $service->end($assignment, '2026-03-01', $actor)->ends_on->format('Y-m-d'));
        $this->assertSame(1, $this->rows(EmployeeAssignment::class, $this->active->id));
    }

    #[Test]
    public function the_write_paths_use_the_one_validator_and_it_is_never_authority(): void
    {
        foreach ([
            'app/Domain/AcademicStructure/Application/RoomAdministrationService.php',
            'app/Domain/AcademicStructure/Application/SectionAdministrationService.php',
            'app/Domain/AcademicStructure/Application/SubjectOfferingService.php',
            'app/Domain/Timetable/Application/TimetableScheduleService.php',
            'app/Domain/Admissions/Application/AdmissionApplicationService.php',
            'app/Domain/HR/Application/EmployeeAssignmentService.php',
        ] as $path) {
            $this->assertMatchesRegularExpression('/\$this->activeCampus->(require|find)\(/', (string) file_get_contents(base_path($path)), "{$path} validates the target Campus through ActiveCampus");
        }

        $code = '';
        foreach (token_get_all((string) file_get_contents(base_path('app/Domain/Campuses/Application/ActiveCampus.php'))) as $token) {
            $code .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
        }
        $validator = $code;
        foreach (['CampusScope', 'Gate', 'authorize', 'CapabilityResolver', '->user('] as $authority) {
            $this->assertStringNotContainsString($authority, $validator, 'ActiveCampus checks data validity, never who may act');
        }
    }
}
