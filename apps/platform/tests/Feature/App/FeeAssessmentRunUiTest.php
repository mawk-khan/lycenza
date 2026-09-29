<?php

namespace Tests\Feature\App;

use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Fees\Infrastructure\FeeAssessmentRun;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * FEE.2: the Finance -> Assessment runs browser pages
 * (App\Http\Controllers\App\Finance\FeeAssessmentRunController). Domain
 * rules are proven by the service, API, RLS and race suites; this covers
 * rendering, capability-aware props, the preview -> exclude -> re-preview
 * -> execute workflow through the pages, and that a hidden button is never
 * the only protection.
 */
class FeeAssessmentRunUiTest extends TestCase
{
    use CreatesFeeAssessmentFixtures;

    private function memberWith(array $capabilities, School $school): User
    {
        $role = Role::query()->create(['key' => 'fee-run-ui-'.uniqid(), 'name' => 'Test', 'scope' => 'school', 'is_system' => false]);
        $role->capabilities()->sync($capabilities);
        $user = $this->createUser();
        $this->assignSchoolRole($this->createMembership($user, $school), $role->key);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    #[Test]
    public function the_runs_page_needs_charge_view_and_only_runners_get_the_create_form(): void
    {
        $w = $this->assessmentWorld();

        $this->memberWith(['finance.fee_structures.view'], $w['school']);
        $this->get('/app/finance/fee-runs')->assertForbidden();

        $this->memberWith(['finance.charges.view'], $w['school']);
        $this->get('/app/finance/fee-runs')->assertInertia(fn ($page) => $page
            ->component('App/Finance/FeeSetup/Runs')->where('canRun', false)->where('activeStructures', []));

        $this->memberWith(['finance.charges.view', 'finance.fee_assessments.run', 'finance.fee_structures.view'], $w['school']);
        $this->get('/app/finance/fee-runs')->assertInertia(fn ($page) => $page
            ->where('canRun', true)
            ->has('activeStructures', 1)
            ->where('activeStructures.0.periods.0.key', 'T1'));
    }

    #[Test]
    public function a_view_only_member_cannot_post_any_run_action(): void
    {
        $w = $this->assessmentWorld();
        $this->enroll($w);
        $run = $this->previewedRun($w);
        $item = $this->items($w, $run)->sole();
        $this->memberWith(['finance.charges.view'], $w['school']);

        foreach (['/app/finance/fee-runs', "/app/finance/fee-runs/{$run->id}/preview", "/app/finance/fee-runs/{$run->id}/items/{$item->id}/exclude",
            "/app/finance/fee-runs/{$run->id}/execute", "/app/finance/fee-runs/{$run->id}/resume", "/app/finance/fee-runs/{$run->id}/cancel"] as $url) {
            $this->post($url, [])->assertForbidden();
        }
    }

    #[Test]
    public function the_full_workflow_runs_through_the_pages(): void
    {
        $w = $this->assessmentWorld();
        $keep = $this->enroll($w);
        $drop = $this->enroll($w);
        $this->memberWith(['finance.charges.view', 'finance.charges.manage', 'finance.fee_assessments.run', 'finance.fee_structures.view'], $w['school']);

        $this->post('/app/finance/fee-runs', ['fee_structure_id' => $w['structure']->id, 'billing_period_key' => 'T1'])->assertRedirect();
        $run = $this->inSchool($w['school'], fn () => FeeAssessmentRun::query()->sole());
        $base = "/app/finance/fee-runs/{$run->id}";

        $this->post('/app/finance/fee-runs', ['fee_structure_id' => $w['structure']->id, 'billing_period_key' => 'T1'])->assertSessionHasErrors('action');

        $this->post("{$base}/preview")->assertRedirect($base);
        $this->get($base)->assertInertia(fn ($page) => $page
            ->component('App/Finance/FeeSetup/Run')
            ->where('run.status', 'previewed')
            ->where('run.previewIsCurrent', true)
            ->where('run.preview.readyCount', 2)
            ->where('run.preview.readyAmount', '10000.00')
            ->has('items.data', 2));
        $this->assertSame(0, $this->inSchool($w['school'], fn () => Charge::query()->count()), 'Preview through the page creates no charge.');

        $dropItem = $this->itemFor($w, $run, $drop->student_id);
        $this->post("{$base}/items/{$dropItem->id}/exclude")->assertRedirect($base);
        $this->post("{$base}/execute")->assertRedirect($base)->assertSessionHasErrors('action');

        $this->post("{$base}/preview")->assertRedirect($base);
        $this->post("{$base}/execute")->assertRedirect($base)->assertSessionHasNoErrors();

        $this->get($base)->assertInertia(fn ($page) => $page
            ->where('run.status', 'completed')
            ->where('run.execution.succeededCount', 1)
            ->where('canVoid', true));
        $this->assertSame([$keep->student_id], $this->inSchool($w['school'], fn () => Charge::query()->pluck('student_id')->all()));

        $assessmentId = $this->itemFor($w, $run, $keep->student_id)->fee_assessment_id;
        $this->post("/app/finance/fee-assessments/{$assessmentId}/void", ['reason' => 'wrong'])->assertRedirect();
        $this->assertNotNull($this->inSchool($w['school'], fn () => Charge::query()->sole()->cancelled_at));
    }

    #[Test]
    public function another_schools_run_page_is_a_404(): void
    {
        $a = $this->assessmentWorld();
        $b = $this->assessmentWorld();
        $foreign = $this->runs()->create($b['school'], $b['structure']->id, 'T1', $b['actor']);
        $this->memberWith(['finance.charges.view', 'finance.fee_assessments.run'], $a['school']);

        $this->get("/app/finance/fee-runs/{$foreign->id}")->assertNotFound();
        $this->post("/app/finance/fee-runs/{$foreign->id}/preview")->assertNotFound();
    }
}
