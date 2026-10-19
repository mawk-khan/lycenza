<?php

namespace Tests\Feature\Examinations;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\Examinations\Infrastructure\Examination;
use App\Domain\Examinations\Infrastructure\ExaminationPaper;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SchoolFactory;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesExaminationPaperFixtures;
use Tests\TestCase;

/**
 * ORG.7D-1 D1-F (ADR 0076 §37): Examination Papers
 * (`examinations.papers.view`/`.manage`) are Campus-adopted -- and nothing
 * else in Examinations. A Campus-scoped holder sees and schedules only its
 * Campuses' papers; another Campus's paper or Offering is NOT FOUND; the
 * Examination itself, grade scales and every marks surface stay whole-School.
 */
class ExaminationPaperCampusScopeTest extends TestCase
{
    use CreatesExaminationPaperFixtures;

    private School $school;

    private Campus $a;

    private Campus $b;

    private Examination $examination;

    /** @var array<string, array{offering: SubjectOffering, spare: SubjectOffering, paper: ExaminationPaper}> */
    private array $at = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North Campus']);
        $this->b = $this->createCampus($this->school, ['name' => 'South Campus']);
        $year = $this->createAcademicYear($this->school, ['status' => 'active']);
        $this->examination = $this->createExamination($year, [
            'starts_on' => $this->today()->addDays(5)->toDateString(), 'ends_on' => $this->today()->addDays(35)->toDateString(),
        ]);
        $grade = $this->createGradeLevel($this->school);
        foreach (['a' => $this->a, 'b' => $this->b] as $key => $campus) {
            $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($this->school), ['status' => 'active']);
            $spare = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($this->school), ['status' => 'active']);
            $paper = $this->createExaminationPaper($this->examination, $offering, ['scheduled_on' => $this->today()->addDays(6)->toDateString()]);
            $this->at[$key] = compact('offering', 'spare', 'paper');
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

    private function newPaper(string $offeringId): array
    {
        return [
            'subject_offering_id' => $offeringId, 'scheduled_on' => $this->today()->addDays(7)->toDateString(),
            'starts_at' => '09:00:00', 'ends_at' => '10:00:00', 'max_marks' => '50',
        ];
    }

    #[Test]
    public function a_whole_school_holder_is_unchanged(): void
    {
        $user = $this->principal([null]);

        $this->api($user)->getJson($this->url("/examinations/{$this->examination->id}/examination-papers"))->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get("/app/examinations/{$this->examination->id}/papers")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('papers', 2)->has('subjectOfferings', 4)->where('canManage', true));
    }

    #[Test]
    public function a_campus_a_holder_sees_and_schedules_only_campus_a_papers(): void
    {
        $user = $this->principal([$this->a->id]);
        $a = $this->at['a'];
        $b = $this->at['b'];

        $ids = $this->api($user)->getJson($this->url("/examinations/{$this->examination->id}/examination-papers"))->assertOk()->json('data.*.id');
        $this->assertSame([$a['paper']->id], $ids);
        $this->api($user)->getJson($this->url("/examination-papers/{$b['paper']->id}"))->assertNotFound();
        $this->api($user)->patchJson($this->url("/examination-papers/{$b['paper']->id}"), ['max_marks' => '80'])->assertNotFound();
        $this->api($user)->postJson($this->url("/examinations/{$this->examination->id}/examination-papers"), $this->newPaper($b['spare']->id))->assertNotFound();

        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get("/app/examinations/{$this->examination->id}/papers")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('papers', 1)->where('papers.0.id', $a['paper']->id)
            ->where('subjectOfferings', fn ($options) => collect($options)->pluck('id')->sort()->values()->all() === collect([$a['offering']->id, $a['spare']->id])->sort()->values()->all())
            ->where('canManage', true));
        $this->patch("/app/examinations/{$this->examination->id}/papers/{$b['paper']->id}", ['max_marks' => '80'])->assertNotFound();
        $this->post("/app/examinations/{$this->examination->id}/papers", $this->newPaper($b['spare']->id))->assertNotFound();
        $this->assertSame('100.00', (string) app(TenantContext::class)->withSchool($this->school, fn () => ExaminationPaper::query()->findOrFail($b['paper']->id)->max_marks));
        $this->assertSame(2, app(TenantContext::class)->withSchool($this->school, fn () => ExaminationPaper::query()->count()));

        // Allowed in Campus A.
        $this->api($user)->postJson($this->url("/examinations/{$this->examination->id}/examination-papers"), $this->newPaper($a['spare']->id))->assertCreated();
        $this->patch("/app/examinations/{$this->examination->id}/papers/{$a['paper']->id}", ['max_marks' => '80'])->assertRedirect();
    }

    #[Test]
    public function the_examination_grade_scales_and_marks_stay_whole_school(): void
    {
        $user = $this->principal([$this->a->id]);
        $scopes = app(CampusScopeResolver::class);

        foreach (['examinations.definitions.view', 'examinations.grade_scales.view', 'examinations.marks.view', 'examinations.marks.manage', 'examinations.marks.lock', 'examinations.marks.correction.approve'] as $capability) {
            $this->assertTrue($scopes->campusScope($user, $capability, $this->school)->isNone(), "{$capability} must confer nothing through a Campus grant");
        }
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/examinations')->assertForbidden();
        $this->get('/app/examinations/grade-scales')->assertForbidden();
        $this->getJson("/app/examination-papers/{$this->at['a']['paper']->id}/marks")->assertStatus(403);
    }

    #[Test]
    public function the_module_and_campus_state_gate_campus_authority(): void
    {
        $user = $this->principal([$this->a->id]);
        $paper = '/examination-papers/'.$this->at['a']['paper']->id;

        SchoolFactory::setOptionalModules($this->school, false, ['examinations']);
        $this->api($user)->getJson($this->url($paper))->assertForbidden();
        SchoolFactory::setOptionalModules($this->school, true, ['examinations']);
        $this->api($user)->getJson($this->url($paper))->assertOk();

        app(TenantContext::class)->withSchool($this->school, fn () => $this->a->forceFill(['status' => 'inactive'])->save());
        $this->api($user)->getJson($this->url($paper))->assertForbidden();
    }
}
