<?php

namespace Tests\Feature\CurriculumDelivery;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\CurriculumDelivery\Infrastructure\CurriculumDelivery;
use App\Domain\Syllabus\Infrastructure\SyllabusUnit;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use Database\Factories\SchoolFactory;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\CurriculumDelivery\Concerns\CreatesCurriculumDeliveryFixtures;
use Tests\TestCase;

/**
 * ORG.7D-1 D1-D (ADR 0076 §37): administrative Curriculum Delivery
 * (`curriculum.delivery.view`/`.manage`) is Campus-adopted. A Campus-scoped
 * administrator sees and records delivery only for its Campuses' Offerings
 * and Sections; another Campus's delivery, Offering, Section or Syllabus
 * Unit is NOT FOUND (a Unit is never confirmed to exist); the syllabus
 * catalogue stays whole-School.
 */
class CurriculumDeliveryCampusScopeTest extends TestCase
{
    use CreatesCurriculumDeliveryFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    /** @var array<string, array{offering: SubjectOffering, section: Section, unit: SyllabusUnit, spare: SyllabusUnit, delivery: CurriculumDelivery}> */
    private array $at = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North Campus']);
        $this->b = $this->createCampus($this->school, ['name' => 'South Campus']);
        $year = $this->createAcademicYear($this->school, [
            'status' => 'active', 'starts_on' => $this->today()->subMonths(6)->toDateString(), 'ends_on' => $this->today()->addMonths(6)->toDateString(),
        ]);
        $grade = $this->createGradeLevel($this->school);
        foreach (['a' => $this->a, 'b' => $this->b] as $key => $campus) {
            $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($this->school), ['is_required' => true, 'status' => 'active']);
            $section = $this->createSection($year, $campus, $grade, ['code' => 'S'.strtoupper($key), 'status' => 'active']);
            $unit = $this->createSyllabusUnitFor($offering, ['code' => 'U1', 'sequence' => 1]);
            $spare = $this->createSyllabusUnitFor($offering, ['code' => 'U2', 'sequence' => 2]);
            $delivery = $this->createDelivery($offering, $section, $unit);
            $this->at[$key] = compact('offering', 'section', 'unit', 'spare', 'delivery');
        }
    }

    /** @param  list<?string>  $campusIds  null = whole School */
    private function principal(array $campusIds): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $this->school);
        foreach ($campusIds as $campusId) {
            $this->assignSchoolRole($membership, 'principal', $campusId);
        }

        return $user;
    }

    private function api(User $user): self
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken);
    }

    private function url(string $path): string
    {
        return "/api/v1/schools/{$this->school->id}{$path}";
    }

    private function deliveryCount(): int
    {
        return $this->inDeliverySchool($this->school, fn () => CurriculumDelivery::query()->count());
    }

    #[Test]
    public function a_whole_school_administrator_is_unchanged(): void
    {
        $user = $this->principal([null]);

        $this->api($user)->getJson($this->url('/subject-offerings/'.$this->at['b']['offering']->id.'/curriculum-deliveries'))->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/syllabus-delivery')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('offerings', 2)->where('canManage', true));
        // A Unit of another Offering stays the specific 422, as before.
        $this->api($user)->postJson($this->url('/subject-offerings/'.$this->at['a']['offering']->id.'/curriculum-deliveries'), [
            'section_id' => $this->at['a']['section']->id, 'syllabus_unit_id' => $this->at['b']['spare']->id, 'started_on' => $this->today()->toDateString(),
        ])->assertStatus(422);
    }

    #[Test]
    public function a_campus_a_administrator_sees_and_records_only_campus_a(): void
    {
        $user = $this->principal([$this->a->id]);
        $a = $this->at['a'];
        $b = $this->at['b'];

        // Reads: Campus A only; Campus B's Offering and delivery are NOT FOUND.
        $this->api($user)->getJson($this->url("/subject-offerings/{$a['offering']->id}/curriculum-deliveries"))->assertOk()->assertJsonCount(1, 'data');
        $this->api($user)->getJson($this->url("/subject-offerings/{$b['offering']->id}/curriculum-deliveries"))->assertNotFound();
        $this->api($user)->getJson($this->url("/curriculum-deliveries/{$b['delivery']->id}"))->assertNotFound();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/syllabus-delivery')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('offerings', 1)->where('offerings.0.id', $a['offering']->id)->where('canManage', true));
        // A request filter only narrows: naming Campus B's Offering shows nothing.
        $this->get("/app/syllabus-delivery?subject_offering_id={$b['offering']->id}&section_id={$b['section']->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('filters.subjectOfferingId', '')->where('rows', []));

        // Writes on Campus B -- the Offering, the Section, a Unit, or an existing delivery -- are NOT FOUND and change nothing.
        $before = $this->deliveryCount();
        $start = fn (string $offering, string $section, string $unit) => $this->api($user)->postJson($this->url("/subject-offerings/{$offering}/curriculum-deliveries"), [
            'section_id' => $section, 'syllabus_unit_id' => $unit, 'started_on' => $this->today()->toDateString(),
        ]);
        $start($b['offering']->id, $b['section']->id, $b['spare']->id)->assertNotFound();
        $start($a['offering']->id, $b['section']->id, $a['spare']->id)->assertNotFound();
        // A Unit of another Campus's Offering is NOT FOUND -- never "belongs to a different Offering".
        $start($a['offering']->id, $a['section']->id, $b['spare']->id)->assertNotFound();
        $this->api($user)->patchJson($this->url("/curriculum-deliveries/{$b['delivery']->id}"), ['started_on' => $this->today()->subDays(2)->toDateString()])->assertNotFound();
        $this->api($user)->postJson($this->url("/curriculum-deliveries/{$b['delivery']->id}/transition"), [
            'expected_status' => 'in_progress', 'new_status' => 'completed', 'completed_on' => $this->today()->toDateString(),
        ])->assertNotFound();
        $this->post("/app/syllabus-delivery/{$b['delivery']->id}/transition", ['expected_status' => 'in_progress', 'new_status' => 'completed', 'completed_on' => $this->today()->toDateString()])->assertNotFound();
        $this->post('/app/syllabus-delivery', ['subject_offering_id' => $b['offering']->id, 'section_id' => $b['section']->id, 'syllabus_unit_id' => $b['spare']->id, 'started_on' => $this->today()->toDateString()])->assertNotFound();
        $this->assertSame($before, $this->deliveryCount());
        $this->assertSame('in_progress', $this->inDeliverySchool($this->school, fn () => CurriculumDelivery::query()->findOrFail($b['delivery']->id)->status));

        // Allowed in Campus A.
        $start($a['offering']->id, $a['section']->id, $a['spare']->id)->assertCreated();
        $this->api($user)->postJson($this->url("/curriculum-deliveries/{$a['delivery']->id}/transition"), [
            'expected_status' => 'in_progress', 'new_status' => 'completed', 'completed_on' => $this->today()->toDateString(),
        ])->assertOk();
    }

    #[Test]
    public function an_out_of_scope_delivery_answers_exactly_like_an_unknown_one(): void
    {
        // Security review MEDIUM-1: same status AND body, so a uuid cannot be probed for existence.
        $user = $this->principal([$this->a->id]);
        $unknown = (string) Str::uuid();
        // The message may echo the id the caller supplied (their own input), so that id is normalized.
        $normalize = fn (array $error, string $id): string => str_replace($id, '{id}', (string) json_encode(array_diff_key($error, ['requestId' => true])));
        $body = fn (string $id, array $payload) => $normalize((array) $this->api($user)->postJson($this->url("/curriculum-deliveries/{$id}/transition"), $payload)->assertNotFound()->json('error'), $id);
        $transition = ['expected_status' => 'in_progress', 'new_status' => 'completed', 'completed_on' => $this->today()->toDateString()];

        $this->assertSame($body($unknown, $transition), $body($this->at['b']['delivery']->id, $transition));
        $patch = fn (string $id) => $normalize((array) $this->api($user)->patchJson($this->url("/curriculum-deliveries/{$id}"), ['started_on' => $this->today()->subDay()->toDateString()])->assertNotFound()->json('error'), $id);
        $this->assertSame($patch($unknown), $patch($this->at['b']['delivery']->id));
    }

    #[Test]
    public function the_syllabus_stays_whole_school_and_module_or_campus_state_removes_authority(): void
    {
        $user = $this->principal([$this->a->id]);

        // Curriculum definition is whole-School configuration: a Campus grant never opens it.
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/syllabus')->assertForbidden();

        SchoolFactory::setOptionalModules($this->school, false, ['curriculum']);
        $this->api($user)->getJson($this->url('/curriculum-deliveries/'.$this->at['a']['delivery']->id))->assertForbidden();
        SchoolFactory::setOptionalModules($this->school, true, ['curriculum']);
        $this->api($user)->getJson($this->url('/curriculum-deliveries/'.$this->at['a']['delivery']->id))->assertOk();

        $this->inDeliverySchool($this->school, fn () => $this->a->forceFill(['status' => 'inactive'])->save());
        $this->api($user)->getJson($this->url('/curriculum-deliveries/'.$this->at['a']['delivery']->id))->assertForbidden();
    }
}
