<?php

namespace Tests\Feature\App;

use App\Domain\AcademicStructure\Infrastructure\AcademicYear;
use App\Domain\Admissions\Application\AdmissionConversionService;
use App\Domain\Admissions\Infrastructure\AdmissionApplication;
use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Hostel\Application\HostelResidencyService;
use App\Domain\Hostel\Infrastructure\HostelFeeSelection;
use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Transport\Application\TransportStudentAssignmentService;
use App\Domain\Transport\Infrastructure\TransportFeeSelection;
use App\Domain\Transport\Infrastructure\TransportRouteFeeHead;
use App\Models\SchoolAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * SWC.3 (ADR 0073 section 19): the OPF fee surfaces for Transport, Hostel
 * and Admissions on the web -- fee-head mappings, recorded fee intent and
 * next-year carry-forward -- over the OPF services `/api/v1` uses. Fee
 * intent is optional-fee selection, never a charge; carry-forward is
 * idempotent by construction (X5); another School's route, hostel, room,
 * application or fee head is a 404 or a refusal, never a write.
 */
class OperationalFeeUiTest extends TestCase
{
    use CreatesFeeAssessmentFixtures;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $w = $this->feeWorld();
        $w['section'] = $this->createSection($w['year'], $w['campus'], $w['grade']);
        $w['optionalHead'] = $this->makeFeeHead($w, ['code' => 'OPF', 'name' => 'Transport']);
        $w['tuition'] = $this->makeFeeHead($w);
        $w['structure'] = $this->activeTwoTermStructure($w, ['code' => 'G-DEF'], $w['optionalHead'], true, $w['tuition']);

        return $w;
    }

    private function staff(array $w, array $capabilities): User
    {
        $user = $this->createUserWithCapabilities($w['school'], $capabilities);
        $this->actingAs($user)->post("/app/schools/{$w['school']->id}/activate");

        return $user;
    }

    // --- Transport -----------------------------------------------------------

    #[Test]
    public function a_route_fee_head_is_set_shown_and_cleared_and_only_an_active_head_of_this_school_is_accepted(): void
    {
        $w = $this->world();
        $route = $this->createTransportRoute($w['school']);
        $manager = $this->staff($w, ['transport.routes.view', 'transport.routes.manage']);

        $this->actingAs($manager)->get("/app/transport/routes/{$route->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('feePanel.feeHead', null)->where('feePanel.canManage', true)
            ->where('feePanel.options', fn ($o) => collect($o)->pluck('id')->contains($w['optionalHead']->id)));

        $this->actingAs($manager)->put("/app/transport/routes/{$route->id}/fee-head", ['fee_head_id' => $w['optionalHead']->id])
            ->assertRedirect(route('app.transport.routes.show', $route->id))->assertSessionHasNoErrors();
        $this->actingAs($manager)->put("/app/transport/routes/{$route->id}/fee-head", ['fee_head_id' => $w['optionalHead']->id])->assertSessionHasNoErrors(); // a repeat converges
        $this->assertSame(1, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()->where('event_type', 'transport.route_fee_head.set')->count()));
        $this->actingAs($manager)->get("/app/transport/routes/{$route->id}")->assertInertia(fn ($page) => $page
            ->where('feePanel.feeHead.id', $w['optionalHead']->id)->where('feePanel.feeHead.name', 'Transport'));

        // Another School's fee head, and an inactive head, are refused as form errors.
        $foreign = $this->makeFeeHead($this->feeWorld(), ['code' => 'FOREIGN']);
        $this->actingAs($manager)->put("/app/transport/routes/{$route->id}/fee-head", ['fee_head_id' => $foreign->id])->assertSessionHasErrors('fee_head_id');
        $inactive = $this->makeFeeHead($w, ['code' => 'OLD']);
        $this->inSchool($w['school'], fn () => FeeHead::query()->whereKey($inactive->id)->update(['status' => 'inactive']));
        $this->actingAs($manager)->put("/app/transport/routes/{$route->id}/fee-head", ['fee_head_id' => $inactive->id])->assertSessionHasErrors('fee_head_id');
        $this->actingAs($manager)->put("/app/transport/routes/{$route->id}/fee-head", ['fee_head_id' => 'not-a-uuid'])->assertSessionHasErrors('fee_head_id');
        $this->assertSame($w['optionalHead']->id, $this->inSchool($w['school'], fn () => TransportRouteFeeHead::query()->where('route_id', $route->id)->value('fee_head_id')));

        $this->actingAs($manager)->put("/app/transport/routes/{$route->id}/fee-head", ['fee_head_id' => null])->assertSessionHasNoErrors();
        $this->assertSame(0, $this->inSchool($w['school'], fn () => TransportRouteFeeHead::query()->where('route_id', $route->id)->count()));
    }

    #[Test]
    public function transport_fee_intent_is_shown_and_carry_forward_records_it_once_with_no_charge(): void
    {
        $w = $this->world();
        $route = $this->createTransportRoute($w['school']);
        $manager = $this->staff($w, ['transport.routes.view', 'transport.routes.manage', 'transport.assignments.view', 'transport.assignments.manage']);
        $this->actingAs($manager)->put("/app/transport/routes/{$route->id}/fee-head", ['fee_head_id' => $w['optionalHead']->id]);
        $student = $this->inSchool($w['school'], fn () => $this->enroll($w)->student);
        $assignment = $this->inSchool($w['school'], fn () => app(TransportStudentAssignmentService::class)->assign($student, $route, null, null, $manager));

        $this->actingAs($manager)->get('/app/transport/fees')->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Transport/Fees')
            ->where('assignments.data.0.id', $assignment->id)
            ->where('assignments.data.0.intent.0.academicYear', '2026-27')
            ->where('assignments.data.0.intent.0.feeHead.id', $w['optionalHead']->id)
            ->where('assignments.data.0.intent.0.linkReason', 'assignment')
            ->where('assignments.data.0.intent.0.selectionOutcome', 'created')
            // UAT-C6 (RA-LIB-DATES root): an instant, shown in School time -- never a bare UTC date.
            ->where('assignments.data.0.intent.0.recordedAt', fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $v) === 1)
            ->where('openYears', fn ($y) => collect($y)->pluck('id')->all() === [$w['year']->id])
            ->where('canCarryForward', true));

        // Next year: open (draft), its own structure with the optional line, the Student enrolled.
        $next = $this->createAcademicYear($w['school'], ['code' => 'AY2027', 'name' => '2027-28', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31', 'status' => 'draft']);
        $this->createStudentEnrollment($student, $this->createSection($next, $w['campus'], $w['grade'], ['code' => 'NX']), ['starts_on' => '2027-06-01']);
        $this->nextYearStructure($w, $next);
        $linked = fn () => $this->inSchool($w['school'], fn () => TransportFeeSelection::query()->where('transport_student_assignment_id', $assignment->id)->count());
        $selections = fn () => $this->inSchool($w['school'], fn () => FeeOptionalSelection::query()->where('student_id', $student->id)->where('academic_year_id', $next->id)->count());

        $this->actingAs($manager)->post('/app/transport/fees/carry-forward', ['academic_year_id' => $next->id])
            ->assertRedirect(route('app.transport.fees'))->assertSessionHasNoErrors()
            ->assertSessionHas('opf.transport.carry_forward', fn ($t) => $t['linked'] === 1 && $t['alreadyLinked'] === 0);
        // X5: a double submission records nothing twice.
        $this->actingAs($manager)->post('/app/transport/fees/carry-forward', ['academic_year_id' => $next->id])
            ->assertSessionHas('opf.transport.carry_forward', fn ($t) => $t['linked'] === 0 && $t['alreadyLinked'] === 1);
        $this->assertSame(2, $linked());
        $this->assertSame(1, $selections());
        $this->assertSame(0, $this->inSchool($w['school'], fn () => Charge::query()->where('student_id', $student->id)->count()), 'intent is never a charge');

        // A closed or foreign year is a form error, not a 500.
        $closed = $this->createAcademicYear($w['school'], ['code' => 'AY2020', 'status' => 'closed', 'starts_on' => '2020-06-01', 'ends_on' => '2021-05-31']);
        $this->actingAs($manager)->post('/app/transport/fees/carry-forward', ['academic_year_id' => $closed->id])->assertSessionHasErrors('academic_year_id');
        $foreignYear = $this->createAcademicYear($this->createSchool(), ['status' => 'draft']);
        $this->actingAs($manager)->post('/app/transport/fees/carry-forward', ['academic_year_id' => $foreignYear->id])->assertSessionHasErrors('academic_year_id');
        $this->assertSame(2, $linked());
    }

    #[Test]
    public function transport_fee_surfaces_follow_the_transport_capabilities(): void
    {
        $w = $this->world();
        $route = $this->createTransportRoute($w['school']);
        $viewer = $this->staff($w, ['transport.routes.view', 'transport.assignments.view']);

        $this->actingAs($viewer)->get("/app/transport/routes/{$route->id}")->assertInertia(fn ($page) => $page->where('feePanel.canManage', false)->where('feePanel.options', []));
        $this->actingAs($viewer)->put("/app/transport/routes/{$route->id}/fee-head", ['fee_head_id' => $w['optionalHead']->id])->assertForbidden();
        $this->actingAs($viewer)->get('/app/transport/fees')->assertOk()->assertInertia(fn ($page) => $page->where('canCarryForward', false));
        $this->actingAs($viewer)->post('/app/transport/fees/carry-forward', ['academic_year_id' => $w['year']->id])->assertForbidden();

        $nobody = $this->staff($w, []);
        $this->actingAs($nobody)->get('/app/transport/fees')->assertForbidden();

        // Another School's route is a 404 for this School's manager.
        $manager = $this->staff($w, ['transport.routes.view', 'transport.routes.manage']);
        $foreignRoute = $this->createTransportRoute($this->createSchool());
        $this->assertSame(404, $this->actingAs($manager)->put("/app/transport/routes/{$foreignRoute->id}/fee-head", ['fee_head_id' => $w['optionalHead']->id])->getStatusCode());
    }

    // --- Hostel --------------------------------------------------------------

    #[Test]
    public function hostel_and_room_fee_heads_show_which_one_governs_and_carry_forward_is_idempotent(): void
    {
        $w = $this->world();
        $premium = $this->makeFeeHead($w, ['code' => 'PRM', 'name' => 'Premium']);
        $hostel = $this->createHostel($w['school'], $w['campus']);
        $room = $this->createHostelRoom($hostel, ['code' => 'R-1']);
        $manager = $this->staff($w, ['hostel.directory.view', 'hostel.directory.manage', 'hostel.residency.view', 'hostel.residency.manage']);

        $this->actingAs($manager)->get("/app/hostel-rooms/{$room->id}")->assertInertia(fn ($page) => $page->where('feePanel.governing', null));
        $this->actingAs($manager)->put("/app/hostels/{$hostel->id}/fee-head", ['fee_head_id' => $w['optionalHead']->id])->assertRedirect(route('app.hostels.show', $hostel->id))->assertSessionHasNoErrors();
        $this->actingAs($manager)->get("/app/hostels/{$hostel->id}")->assertInertia(fn ($page) => $page->where('feePanel.feeHead.id', $w['optionalHead']->id));
        $this->actingAs($manager)->get("/app/hostel-rooms/{$room->id}")->assertInertia(fn ($page) => $page
            ->where('feePanel.governing', 'hostel')->where('feePanel.hostelDefault.id', $w['optionalHead']->id)->where('feePanel.feeHead', null));
        $this->actingAs($manager)->put("/app/hostel-rooms/{$room->id}/fee-head", ['fee_head_id' => $premium->id])->assertRedirect(route('app.hostel-rooms.show', $room->id))->assertSessionHasNoErrors();
        $this->actingAs($manager)->get("/app/hostel-rooms/{$room->id}")->assertInertia(fn ($page) => $page
            ->where('feePanel.governing', 'room')->where('feePanel.feeHead.id', $premium->id));

        $foreign = $this->makeFeeHead($this->feeWorld(), ['code' => 'FOREIGN']);
        $this->actingAs($manager)->put("/app/hostels/{$hostel->id}/fee-head", ['fee_head_id' => $foreign->id])->assertSessionHasErrors('fee_head_id');
        $otherSchool = $this->feeWorld();
        $foreignHostel = $this->createHostel($otherSchool['school'], $otherSchool['campus']);
        $foreignRoom = $this->createHostelRoom($foreignHostel);
        $this->assertSame(404, $this->actingAs($manager)->put("/app/hostels/{$foreignHostel->id}/fee-head", ['fee_head_id' => $w['optionalHead']->id])->getStatusCode());
        $this->assertSame(404, $this->actingAs($manager)->put("/app/hostel-rooms/{$foreignRoom->id}/fee-head", ['fee_head_id' => $w['optionalHead']->id])->getStatusCode());

        // A residency in the room records the room's head (the governing one).
        $this->actingAs($manager)->put("/app/hostel-rooms/{$room->id}/fee-head", ['fee_head_id' => $w['optionalHead']->id]);
        $student = $this->inSchool($w['school'], fn () => $this->enroll($w)->student);
        $residency = $this->inSchool($w['school'], fn () => app(HostelResidencyService::class)->assign($student, $this->createHostelBed($room, ['code' => 'B-1']), $manager));
        $this->actingAs($manager)->get('/app/hostel-residency/fees')->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Hostel/Fees')
            ->where('residencies.data.0.id', $residency->id)
            ->where('residencies.data.0.intent.0.mappingScope', 'room')
            ->where('residencies.data.0.intent.0.feeHead.id', $w['optionalHead']->id));

        $next = $this->createAcademicYear($w['school'], ['code' => 'AY2027', 'name' => '2027-28', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31', 'status' => 'draft']);
        $this->createStudentEnrollment($student, $this->createSection($next, $w['campus'], $w['grade'], ['code' => 'NX']), ['starts_on' => '2027-06-01']);
        $this->nextYearStructure($w, $next);
        $this->actingAs($manager)->post('/app/hostel-residency/fees/carry-forward', ['academic_year_id' => $next->id])
            ->assertRedirect(route('app.hostel-residency.fees'))->assertSessionHas('opf.hostel.carry_forward', fn ($t) => $t['linked'] === 1);
        $this->actingAs($manager)->post('/app/hostel-residency/fees/carry-forward', ['academic_year_id' => $next->id])
            ->assertSessionHas('opf.hostel.carry_forward', fn ($t) => $t['linked'] === 0 && $t['alreadyLinked'] === 1);
        $this->assertSame(2, $this->inSchool($w['school'], fn () => HostelFeeSelection::query()->where('hostel_residency_assignment_id', $residency->id)->count()));
        $this->assertSame(1, $this->inSchool($w['school'], fn () => FeeOptionalSelection::query()->where('student_id', $student->id)->where('academic_year_id', $next->id)->count()));

        $viewer = $this->staff($w, ['hostel.directory.view', 'hostel.residency.view']);
        $this->actingAs($viewer)->put("/app/hostels/{$hostel->id}/fee-head", ['fee_head_id' => null])->assertForbidden();
        $this->actingAs($viewer)->post('/app/hostel-residency/fees/carry-forward', ['academic_year_id' => $next->id])->assertForbidden();
        $this->assertSame(1, $this->inSchool($w['school'], fn () => HostelResidencyAssignment::query()->count()));
    }

    // --- Admissions ----------------------------------------------------------

    #[Test]
    public function the_school_admission_fee_head_is_configured_and_a_conversion_shows_its_intent(): void
    {
        $w = $this->world();
        $manager = $this->staff($w, ['admissions.view', 'admissions.manage']);

        $this->actingAs($manager)->get('/app/admissions/fee-head')->assertOk()->assertInertia(fn ($page) => $page
            ->component('App/Admissions/FeeHead')->where('feeHead', null)->where('canManage', true));
        $this->actingAs($manager)->put('/app/admissions/fee-head', ['fee_head_id' => $w['optionalHead']->id])->assertRedirect(route('app.admissions.fee-head'))->assertSessionHasNoErrors();
        $foreign = $this->makeFeeHead($this->feeWorld(), ['code' => 'FOREIGN']);
        $this->actingAs($manager)->put('/app/admissions/fee-head', ['fee_head_id' => $foreign->id])->assertSessionHasErrors('fee_head_id');
        $this->actingAs($manager)->get('/app/admissions/fee-head')->assertInertia(fn ($page) => $page->where('feeHead.id', $w['optionalHead']->id));

        $application = $this->createAdmissionApplication($this->createApplicant($w['school']), $w['year'], $w['campus'], $w['grade'], ['status' => 'accepted']);
        $this->actingAs($manager)->get("/app/admissions/{$application->id}")->assertInertia(fn ($page) => $page
            ->where('feePanel.configured.id', $w['optionalHead']->id)->where('feePanel.intent', null));

        app(AdmissionConversionService::class)->convert($application, $w['section'], '01', '2026-06-01', null, $manager);
        $this->actingAs($manager)->get("/app/admissions/{$application->id}")->assertOk()->assertInertia(fn ($page) => $page
            ->where('feePanel.intent.feeHead.id', $w['optionalHead']->id)
            ->where('feePanel.intent.selectionOutcome', 'created')
            ->where('compatibleSections', []));

        $viewer = $this->staff($w, ['admissions.view']);
        $this->actingAs($viewer)->get('/app/admissions/fee-head')->assertOk()->assertInertia(fn ($page) => $page->where('canManage', false)->where('options', []));
        $this->actingAs($viewer)->put('/app/admissions/fee-head', ['fee_head_id' => null])->assertForbidden();

        $otherWorld = $this->feeWorld();
        $foreignApplication = $this->createAdmissionApplication($this->createApplicant($otherWorld['school']), $otherWorld['year'], $otherWorld['campus'], $otherWorld['grade'], ['status' => 'accepted']);
        $this->assertSame(404, $this->actingAs($manager)->get("/app/admissions/{$foreignApplication->id}")->getStatusCode());
        $this->assertSame('converted', $this->inSchool($w['school'], fn () => AdmissionApplication::query()->findOrFail($application->id)->status));
    }

    #[Test]
    public function the_conversion_form_offers_no_fee_head_choice(): void
    {
        $source = (string) file_get_contents(resource_path('js/Pages/App/Admissions/Show.vue'));
        preg_match('/const convertForm = useForm\(\{(.*?)\}\);/s', $source, $form);
        $this->assertNotEmpty($form, 'the conversion form is where it was');
        $this->assertStringNotContainsString('fee', strtolower($form[1]), 'ADR 0067 D7: no per-conversion fee head');
    }

    #[Test]
    public function the_fee_intent_pages_issue_no_query_per_row(): void
    {
        $w = $this->world();
        $route = $this->createTransportRoute($w['school']);
        $hostel = $this->createHostel($w['school'], $w['campus']);
        $room = $this->createHostelRoom($hostel);
        $manager = $this->staff($w, ['transport.routes.manage', 'transport.assignments.view', 'transport.assignments.manage', 'hostel.directory.manage', 'hostel.residency.view', 'hostel.residency.manage']);
        $this->actingAs($manager)->put("/app/transport/routes/{$route->id}/fee-head", ['fee_head_id' => $w['optionalHead']->id]);
        $this->actingAs($manager)->put("/app/hostels/{$hostel->id}/fee-head", ['fee_head_id' => $w['optionalHead']->id]);
        $beds = 0;
        $add = function () use ($w, $route, $room, $manager, &$beds): void {
            $student = $this->inSchool($w['school'], fn () => $this->enroll($w)->student);
            $this->inSchool($w['school'], fn () => app(TransportStudentAssignmentService::class)->assign($student, $route, null, null, $manager));
            $code = 'B'.(++$beds);
            $this->inSchool($w['school'], fn () => app(HostelResidencyService::class)->assign($student, $this->createHostelBed($room, ['code' => $code]), $manager));
        };
        $add();
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $count = function (string $url) use (&$queries, $manager): int {
            $queries = 0;
            $this->actingAs($manager)->get($url)->assertOk();

            return $queries;
        };
        $this->actingAs($manager)->get('/app/transport/fees')->assertOk();
        $before = [$count('/app/transport/fees'), $count('/app/hostel-residency/fees')];
        foreach (range(1, 4) as $i) {
            $add();
        }

        $this->assertSame($before, [$count('/app/transport/fees'), $count('/app/hostel-residency/fees')]);
    }

    /** @param  array<string, mixed>  $w */
    private function nextYearStructure(array $w, AcademicYear $next): void
    {
        $service = app(FeeStructureService::class);
        $structure = $service->createDraft($w['school'], ['academic_year_id' => $next->id, 'grade_level_id' => $w['grade']->id, 'campus_id' => null, 'code' => 'G-NEXT', 'name' => 'Next'], $w['actor']);
        foreach ([[$w['optionalHead'], true], [$w['tuition'], false]] as [$head, $optional]) {
            $line = $service->addLine($w['school'], $structure->id, ['fee_head_id' => $head->id, 'amount' => '1000.00', 'is_optional' => $optional], $w['actor']);
            $service->replaceInstallments($w['school'], $structure->id, $line->id, [
                ['label' => 'Year', 'billing_period_key' => 'Y1', 'period_starts_on' => '2027-06-01', 'period_ends_on' => '2028-05-31', 'due_date' => '2027-06-10', 'amount' => '1000.00'],
            ], $w['actor']);
        }
        $service->activate($w['school'], $structure->id, $w['actor']);
    }
}
