<?php

namespace Tests\Feature\TeachingAssignments;

use App\Domain\TeachingAssignments\Http\Controllers\TeachingAssignmentController;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTeachingAssignmentFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * TCH.2 (ADR 0063 section 23): the TeachingAssignment administrative API
 * -- list, show, create, end; no PATCH, no DELETE -- under
 * teaching.assignments.view/.manage, with the standard error envelope,
 * `Cache-Control: no-store, private`, and one tenant-safe 404 for an
 * unknown or other-School id. Documented in the OpenAPI contract.
 */
class TeachingAssignmentApiTest extends TestCase
{
    use CreatesTeachingAssignmentFixtures, CreatesTenancyFixtures;

    private function as(User $user): static
    {
        // Request guards cache the resolved bearer user across requests in
        // one test; forget them so each call authenticates its own token.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('test-device')->plainTextToken)
            ->withHeader('Idempotency-Key', (string) Str::uuid());
    }

    private function create(array $w, array $overrides = [], ?User $actor = null): TestResponse
    {
        return $this->as($actor ?? $w['admin'])->postJson("/api/v1/schools/{$w['school']->id}/teaching-assignments", array_merge([
            'employee_id' => $w['employee']->id,
            'section_id' => $w['section']->id,
            'subject_offering_id' => $w['offering']->id,
            'starts_on' => '2026-06-01',
        ], $overrides));
    }

    #[Test]
    public function a_manager_creates_lists_shows_and_ends_an_assignment(): void
    {
        $w = $this->teachingWorld();

        $created = $this->create($w, ['ends_on' => '2026-12-31'])->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.employee.id', $w['employee']->id)
            ->assertJsonPath('data.employee.fullName', $w['employee']->full_name)
            ->assertJsonPath('data.section.id', $w['section']->id)
            ->assertJsonPath('data.subjectOffering.id', $w['offering']->id)
            ->assertJsonPath('data.startsOn', '2026-06-01')
            ->assertJsonPath('data.endsOn', '2026-12-31')
            ->assertJsonPath('data.endedAt', null);
        $id = $created->json('data.id');
        $this->assertSame(['id', 'employee', 'section', 'subjectOffering', 'academicYearId', 'startsOn', 'endsOn', 'state', 'endedAt', 'endReason'], array_keys($created->json('data')));
        $this->assertSame(['id', 'employeeNumber', 'fullName'], array_keys($created->json('data.employee')), 'Directory-tier only.');
        $this->flushHeaders();

        $this->as($w['admin'])->getJson("/api/v1/schools/{$w['school']->id}/teaching-assignments?section_id={$w['section']->id}")
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->flushHeaders();
        $this->as($w['admin'])->getJson("/api/v1/schools/{$w['school']->id}/teaching-assignments/{$id}")->assertOk()->assertJsonPath('data.id', $id);
        $this->flushHeaders();

        $this->as($w['admin'])->postJson("/api/v1/schools/{$w['school']->id}/teaching-assignments/{$id}/end", ['ends_on' => '2026-09-30', 'reason' => 'reassigned'])
            ->assertOk()->assertJsonPath('data.endsOn', '2026-09-30')->assertJsonPath('data.endReason', 'reassigned');
        $this->flushHeaders();

        $this->as($w['admin'])->postJson("/api/v1/schools/{$w['school']->id}/teaching-assignments/{$id}/end", ['ends_on' => '2026-08-31', 'reason' => 'completed'])
            ->assertStatus(409)->assertJsonPath('error.code', 'TEACHING_ASSIGNMENT_ALREADY_ENDED');
    }

    #[Test]
    public function view_and_manage_are_enforced(): void
    {
        $w = $this->teachingWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['teaching.assignments.view']);
        $nobody = $this->createUserWithCapabilities($w['school'], ['timetable.schedule.manage', 'hr.employees.manage']);

        $this->create($w, actor: $viewer)->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->flushHeaders();
        $this->create($w, actor: $nobody)->assertForbidden();
        $this->flushHeaders();
        $this->as($nobody)->getJson("/api/v1/schools/{$w['school']->id}/teaching-assignments")->assertForbidden();
        $this->flushHeaders();

        $id = $this->create($w)->assertCreated()->json('data.id');
        $this->flushHeaders();
        $this->as($viewer)->getJson("/api/v1/schools/{$w['school']->id}/teaching-assignments")->assertOk()->assertJsonPath('meta.total', 1);
        $this->flushHeaders();
        $this->as($viewer)->postJson("/api/v1/schools/{$w['school']->id}/teaching-assignments/{$id}/end", ['ends_on' => '2026-09-30', 'reason' => 'completed'])->assertForbidden();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/schools/{$w['school']->id}/teaching-assignments", [])->assertUnauthorized();
    }

    #[Test]
    public function other_school_ids_are_the_same_404_as_unknown_ones(): void
    {
        $w = $this->teachingWorld();
        $other = $this->teachingWorld();
        $otherId = app(TenantContext::class)->withSchool($other['school'], fn () => $this->assign($other)->id);

        foreach ([['employee_id' => $other['employee']->id], ['section_id' => $other['section']->id], ['subject_offering_id' => $other['offering']->id], ['employee_id' => (string) Str::uuid7()]] as $override) {
            $this->create($w, $override)->assertNotFound();
            $this->flushHeaders();
        }

        $this->as($w['admin'])->getJson("/api/v1/schools/{$w['school']->id}/teaching-assignments/{$otherId}")->assertNotFound();
        $this->flushHeaders();
        $this->as($w['admin'])->getJson("/api/v1/schools/{$w['school']->id}/teaching-assignments/not-a-uuid")->assertNotFound();
        $this->flushHeaders();
        $this->as($w['admin'])->postJson("/api/v1/schools/{$w['school']->id}/teaching-assignments/{$otherId}/end", ['ends_on' => '2026-09-30', 'reason' => 'completed'])->assertNotFound();
        $this->flushHeaders();
        $this->as($w['admin'])->getJson("/api/v1/schools/{$w['school']->id}/teaching-assignments")->assertOk()->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function domain_refusals_use_stable_codes(): void
    {
        $w = $this->teachingWorld();
        $this->create($w)->assertCreated();
        $this->flushHeaders();

        $this->create($w, ['starts_on' => '2026-07-01'])->assertStatus(409)->assertJsonPath('error.code', 'TEACHING_ASSIGNMENT_OVERLAP');
        $this->flushHeaders();
        $this->create($w, ['starts_on' => '2026-06-02', 'ends_on' => '2026-06-01'])->assertStatus(422)->assertJsonPath('error.code', 'TEACHING_ASSIGNMENT_INVALID_DATES');
        $this->flushHeaders();
        $this->create($w, ['starts_on' => '2027-06-01'])->assertStatus(422)->assertJsonPath('error.code', 'TEACHING_ASSIGNMENT_OUTSIDE_ACADEMIC_YEAR');
        $this->flushHeaders();
        $this->create($w, ['employee_id' => $this->createEmployee($w['school'], ['user_id' => null])->id])->assertStatus(422)->assertJsonPath('error.code', 'TEACHING_ASSIGNMENT_EMPLOYEE_NOT_ASSIGNABLE');
        $this->flushHeaders();
        $this->create($w, ['starts_on' => '01/06/2026'])->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['starts_on']]]);
    }

    #[Test]
    public function the_body_never_supplies_school_or_context_and_end_reasons_are_closed(): void
    {
        $w = $this->teachingWorld();
        $other = $this->teachingWorld();

        $a = $this->create($w, ['school_id' => $other['school']->id, 'academic_year_id' => $other['year']->id, 'grade_level_id' => $other['grade']->id])->assertCreated();
        $this->assertSame($w['year']->id, $a->json('data.academicYearId'));
        $this->flushHeaders();

        $this->as($w['admin'])->postJson("/api/v1/schools/{$w['school']->id}/teaching-assignments/{$a->json('data.id')}/end", ['ends_on' => '2026-09-30', 'reason' => 'fired'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['reason']]]);
    }

    #[Test]
    public function there_is_no_patch_or_delete_and_every_route_is_documented(): void
    {
        $w = $this->teachingWorld();
        $id = $this->assign($w)->id;

        $this->as($w['admin'])->patchJson("/api/v1/schools/{$w['school']->id}/teaching-assignments/{$id}", ['starts_on' => '2026-07-01'])->assertStatus(405);
        $this->flushHeaders();
        $this->as($w['admin'])->deleteJson("/api/v1/schools/{$w['school']->id}/teaching-assignments/{$id}")->assertStatus(405);

        $live = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction('controller');
            if (is_string($action) && explode('@', $action, 2)[0] === TeachingAssignmentController::class) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $live[] = $method.' /'.preg_replace('#^api/v1/#', '', $route->uri());
                }
            }
        }
        sort($live);
        $this->assertSame([
            'GET /schools/{school}/teaching-assignments',
            'GET /schools/{school}/teaching-assignments/{teachingAssignment}',
            'POST /schools/{school}/teaching-assignments',
            'POST /schools/{school}/teaching-assignments/{teachingAssignment}/end',
        ], $live);

        $yaml = (string) file_get_contents(base_path('../../packages/contracts/openapi/school-os-api.yaml'));
        foreach (['listTeachingAssignments', 'getTeachingAssignment', 'createTeachingAssignment', 'endTeachingAssignment'] as $operationId) {
            $this->assertStringContainsString("operationId: {$operationId}\n", $yaml);
        }
        $this->assertStringContainsString("\n  /schools/{schoolId}/teaching-assignments:\n", $yaml);
        $this->assertStringContainsString("\n  /schools/{schoolId}/teaching-assignments/{teachingAssignmentId}/end:\n", $yaml);
    }
}
