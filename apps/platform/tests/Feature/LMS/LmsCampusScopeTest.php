<?php

namespace Tests\Feature\LMS;

use App\Domain\AcademicStructure\Infrastructure\SubjectOffering;
use App\Domain\LMS\Infrastructure\Assignment;
use App\Domain\LMS\Infrastructure\LearningContent;
use App\Models\Campus;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Factories\SchoolFactory;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\LMS\Concerns\CreatesAssignmentFixtures;
use Tests\Feature\LMS\Concerns\CreatesLearningContentFixtures;
use Tests\TestCase;

/**
 * ORG.7D-1 D1-E (ADR 0076 §37): administrative LMS (`lms.content.*`,
 * `lms.assignments.*` view/manage) is Campus-adopted through the owning
 * Subject Offering. A Campus-scoped administrator works only with its
 * Campuses' content and Assignments -- and their attached Documents;
 * another Campus's are NOT FOUND, exactly like an unknown record. Teacher
 * LMS is not adopted.
 */
class LmsCampusScopeTest extends TestCase
{
    use CreatesAssignmentFixtures, CreatesLearningContentFixtures {
        CreatesAssignmentFixtures::inSchool insteadof CreatesLearningContentFixtures;
    }

    private School $school;

    private Campus $a;

    private Campus $b;

    /** @var array<string, array{offering: SubjectOffering, content: LearningContent, assignment: Assignment}> */
    private array $at = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->school = $this->createSchool();
        $this->a = $this->createCampus($this->school, ['name' => 'North Campus']);
        $this->b = $this->createCampus($this->school, ['name' => 'South Campus']);
        $year = $this->createAcademicYear($this->school, ['status' => 'active']);
        $grade = $this->createGradeLevel($this->school);
        foreach (['a' => $this->a, 'b' => $this->b] as $key => $campus) {
            $offering = $this->createSubjectOffering($year, $campus, $grade, $this->createSubject($this->school), ['is_required' => true, 'status' => 'active']);
            $this->at[$key] = [
                'offering' => $offering,
                'content' => $this->createLearningContent($offering, ['title' => 'Content '.strtoupper($key), 'status' => 'draft']),
                'assignment' => $this->createAssignment($offering, ['title' => 'Assignment '.strtoupper($key), 'status' => 'draft', 'due_on' => now()->addMonth()->toDateString()]),
            ];
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

    private function statusOf(string $model, string $id): string
    {
        return app(TenantContext::class)->withSchool($this->school, fn () => $model::query()->findOrFail($id)->status);
    }

    #[Test]
    public function a_whole_school_administrator_is_unchanged(): void
    {
        $user = $this->principal([null]);

        $this->api($user)->getJson($this->url('/learning-content/'.$this->at['b']['content']->id))->assertOk();
        $this->api($user)->getJson($this->url('/assignments/'.$this->at['b']['assignment']->id))->assertOk();
        $this->api($user)->getJson($this->url('/learning-content/'.$this->at['b']['content']->id.'/documents'))->assertOk();
        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/learning-content')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('offerings', 2)->where('canManage', true));
    }

    #[Test]
    public function a_campus_a_administrator_works_only_with_campus_a(): void
    {
        $user = $this->principal([$this->a->id]);
        $a = $this->at['a'];
        $b = $this->at['b'];

        // Reads: Campus A; Campus B's Offering, content, Assignment and their Documents are NOT FOUND.
        $this->api($user)->getJson($this->url("/subject-offerings/{$a['offering']->id}/learning-content"))->assertOk()->assertJsonCount(1, 'data');
        $this->api($user)->getJson($this->url("/subject-offerings/{$b['offering']->id}/learning-content"))->assertNotFound();
        $this->api($user)->getJson($this->url("/subject-offerings/{$b['offering']->id}/assignments"))->assertNotFound();
        $this->api($user)->getJson($this->url("/learning-content/{$b['content']->id}"))->assertNotFound();
        $this->api($user)->getJson($this->url("/assignments/{$b['assignment']->id}"))->assertNotFound();
        $this->api($user)->getJson($this->url("/learning-content/{$a['content']->id}/documents"))->assertOk();
        $this->api($user)->getJson($this->url("/assignments/{$a['assignment']->id}/documents"))->assertOk();
        $this->api($user)->getJson($this->url("/learning-content/{$b['content']->id}/documents"))->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_OWNER_NOT_FOUND');
        $this->api($user)->getJson($this->url("/assignments/{$b['assignment']->id}/documents"))->assertNotFound()->assertJsonPath('error.code', 'DOCUMENT_OWNER_NOT_FOUND');

        $this->actingAs($user)->post("/app/schools/{$this->school->id}/activate");
        $this->get('/app/learning-content')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('offerings', 1)->where('offerings.0.id', $a['offering']->id)->where('canManage', true));
        // A request filter only narrows: Campus B's Offering shows nothing.
        $this->get("/app/assignments?subject_offering_id={$b['offering']->id}")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('filters.subjectOfferingId', ''));

        // Writes on Campus B: NOT FOUND, nothing written or changed.
        $this->api($user)->postJson($this->url("/subject-offerings/{$b['offering']->id}/learning-content"), ['title' => 'X'])->assertNotFound();
        $this->api($user)->postJson($this->url("/subject-offerings/{$b['offering']->id}/assignments"), ['title' => 'X'])->assertNotFound();
        $this->api($user)->patchJson($this->url("/learning-content/{$b['content']->id}"), ['title' => 'Changed'])->assertNotFound();
        $this->api($user)->postJson($this->url("/learning-content/{$b['content']->id}/publish"))->assertNotFound();
        $this->api($user)->postJson($this->url("/assignments/{$b['assignment']->id}/publish"))->assertNotFound();
        $this->post("/app/learning-content/{$b['content']->id}/publish")->assertNotFound();
        $this->post("/app/assignments/{$b['assignment']->id}/publish")->assertNotFound();
        $this->post('/app/assignments', ['subject_offering_id' => $b['offering']->id, 'title' => 'X'])->assertNotFound();
        $this->assertSame(['draft', 'draft'], [$this->statusOf(LearningContent::class, $b['content']->id), $this->statusOf(Assignment::class, $b['assignment']->id)]);
        $this->assertSame(1, app(TenantContext::class)->withSchool($this->school, fn () => LearningContent::query()->where('subject_offering_id', $b['offering']->id)->count()));

        // Allowed in Campus A.
        $this->api($user)->postJson($this->url("/subject-offerings/{$a['offering']->id}/learning-content"), ['title' => 'New'])->assertCreated();
        $this->api($user)->postJson($this->url("/learning-content/{$a['content']->id}/publish"))->assertOk();
        $this->post("/app/assignments/{$a['assignment']->id}/publish")->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('published', $this->statusOf(Assignment::class, $a['assignment']->id));
    }

    #[Test]
    public function the_module_and_campus_state_gate_campus_authority(): void
    {
        $user = $this->principal([$this->a->id]);
        $content = '/learning-content/'.$this->at['a']['content']->id;

        SchoolFactory::setOptionalModules($this->school, false, ['lms']);
        $this->api($user)->getJson($this->url($content))->assertForbidden();
        SchoolFactory::setOptionalModules($this->school, true, ['lms']);
        $this->api($user)->getJson($this->url($content))->assertOk();

        app(TenantContext::class)->withSchool($this->school, fn () => $this->a->forceFill(['status' => 'inactive'])->save());
        $this->api($user)->getJson($this->url($content))->assertForbidden();
        $this->api($user)->getJson($this->url($content.'/documents'))->assertForbidden();
    }
}
