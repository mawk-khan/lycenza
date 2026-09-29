<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Infrastructure\FeeStructureLine;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeSetupFixtures;
use Tests\TestCase;

/**
 * FEE.1: the /api/v1 fee setup and ledger-account administration surface --
 * allow AND deny for every operation (route middleware plus the
 * Application-layer check), clean 422s for case-variant duplicate codes,
 * the money wire format, cross-School 404s, and no DELETE for any
 * permanent record.
 */
class FeeSetupApiTest extends TestCase
{
    use CreatesFeeSetupFixtures;

    private function as(array $w, User $actor): static
    {
        return $this->actingAs($actor)->withHeader('X-School-Id', $w['school']->id);
    }

    private function base(array $w): string
    {
        return "/api/v1/schools/{$w['school']->id}";
    }

    /**
     * Every FEE.1 operation with the one capability it needs and a body
     * that would succeed for an authorized actor.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
     */
    private function operations(array $w): array
    {
        $head = $this->makeFeeHead($w);
        $draft = $this->makeCompleteDraft($w, ['code' => 'DRAFT']);
        $line = $this->inSchool($w['school'], fn () => FeeStructureLine::query()->where('fee_structure_id', $draft->id)->firstOrFail());
        $active = $this->makeCompleteDraft($w, ['code' => 'ACTIVE', 'campus_id' => $w['campus']->id]);
        app(FeeStructureService::class)->activate($w['school'], $active->id, $w['actor']);
        $optional = $this->makeDraftStructure($w, ['code' => 'OPT']);
        $optLine = app(FeeStructureService::class)->addLine($w['school'], $optional->id, ['fee_head_id' => $head->id, 'amount' => '10.00', 'is_optional' => true], $w['actor']);
        $student = $this->createStudent($w['school']);
        $b = $this->base($w);
        $installment = [['label' => 'A', 'billing_period_key' => 'A', 'period_starts_on' => '2026-06-01', 'period_ends_on' => '2027-05-31', 'due_date' => '2026-06-01', 'amount' => '12000.00']];

        return [
            ['POST', "{$b}/ledger-accounts", 'finance.accounts.manage', ['code' => 'NEW1', 'name' => 'New', 'type' => 'asset']],
            ['PATCH', "{$b}/ledger-accounts/{$w['receivable']->id}", 'finance.accounts.manage', ['status' => 'active']],
            ['GET', "{$b}/fee-heads", 'finance.fee_structures.view', []],
            ['POST', "{$b}/fee-heads", 'finance.fee_structures.manage', ['code' => 'LAB', 'name' => 'Lab', 'receivable_ledger_account_id' => $w['receivable']->id, 'revenue_ledger_account_id' => $w['revenue']->id]],
            ['GET', "{$b}/fee-heads/{$head->id}", 'finance.fee_structures.view', []],
            ['PATCH', "{$b}/fee-heads/{$head->id}", 'finance.fee_structures.manage', ['name' => 'Tuition fee']],
            ['GET', "{$b}/fee-structures", 'finance.fee_structures.view', []],
            ['POST', "{$b}/fee-structures", 'finance.fee_structures.manage', ['academic_year_id' => $w['year']->id, 'grade_level_id' => $w['grade']->id, 'code' => 'NEWS', 'name' => 'New']],
            ['GET', "{$b}/fee-structures/{$draft->id}", 'finance.fee_structures.view', []],
            ['PATCH', "{$b}/fee-structures/{$draft->id}", 'finance.fee_structures.manage', ['name' => 'Renamed']],
            ['POST', "{$b}/fee-structures/{$draft->id}/lines/{$line->id}/installments/generate", 'finance.fee_structures.manage', ['frequency' => 'one_time']],
            ['PUT', "{$b}/fee-structures/{$draft->id}/lines/{$line->id}/installments", 'finance.fee_structures.manage', ['installments' => $installment]],
            ['PATCH', "{$b}/fee-structures/{$draft->id}/lines/{$line->id}", 'finance.fee_structures.manage', ['amount' => '12000.00']],
            ['POST', "{$b}/fee-structures/{$draft->id}/activate", 'finance.fee_structures.manage', []],
            ['POST', "{$b}/fee-structures/{$active->id}/successor", 'finance.fee_structures.manage', ['code' => 'ACTIVE-V2']],
            ['POST', "{$b}/fee-structures/{$active->id}/retire", 'finance.fee_structures.manage', []],
            ['POST', "{$b}/fee-structures/{$optional->id}/lines", 'finance.fee_structures.manage', ['fee_head_id' => $this->makeFeeHead($w, ['code' => 'EXTRA'])->id, 'amount' => '5.00']],
            ['DELETE', "{$b}/fee-structures/{$optional->id}/lines/{$optLine->id}", 'finance.fee_structures.manage', []],
            ['POST', "{$b}/fee-optional-selections", 'finance.fee_structures.manage', ['student_id' => $student->id, 'fee_structure_line_id' => $this->optionalLine($w)]],
            ['GET', "{$b}/fee-optional-selections?student_id={$student->id}", 'finance.fee_structures.view', []],
        ];
    }

    private function optionalLine(array $w): string
    {
        $structure = $this->makeDraftStructure($w, ['code' => 'OPT2']);
        $head = $this->makeFeeHead($w, ['code' => 'BUS']);

        return app(FeeStructureService::class)->addLine($w['school'], $structure->id, ['fee_head_id' => $head->id, 'amount' => '1.00', 'is_optional' => true], $w['actor'])->id;
    }

    #[Test]
    public function every_operation_is_denied_without_its_capability_and_allowed_with_it(): void
    {
        $w = $this->feeWorld();
        $operations = $this->operations($w);
        $unrelated = $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'finance.ledger.view']);

        foreach ($operations as [$method, $url, $capability, $body]) {
            $this->as($w, $unrelated)->json($method, $url, $body)
                ->assertForbidden();
        }

        foreach ($operations as [$method, $url, $capability, $body]) {
            $actor = $this->createUserWithCapabilities($w['school'], [$capability]);
            $status = $this->as($w, $actor)->json($method, $url, $body)->status();
            $this->assertContains($status, [200, 201, 204], "{$method} {$url} with {$capability} must succeed, got {$status}.");
        }
    }

    #[Test]
    public function a_view_only_actor_cannot_write_and_a_manager_without_view_cannot_read(): void
    {
        $w = $this->feeWorld();
        $viewer = $this->createUserWithCapabilities($w['school'], ['finance.fee_structures.view']);
        $manager = $this->createUserWithCapabilities($w['school'], ['finance.fee_structures.manage']);
        $b = $this->base($w);

        $this->as($w, $viewer)->postJson("{$b}/fee-heads", ['code' => 'X', 'name' => 'X', 'receivable_ledger_account_id' => $w['receivable']->id, 'revenue_ledger_account_id' => $w['revenue']->id])->assertForbidden();
        $this->as($w, $manager)->getJson("{$b}/fee-heads")->assertForbidden();
    }

    #[Test]
    public function the_principal_role_is_denied_by_default(): void
    {
        $w = $this->feeWorld();
        $principal = $this->createUser();
        $this->assignSchoolRole($this->createMembership($principal, $w['school']), 'principal');

        $this->as($w, $principal)->getJson($this->base($w).'/fee-structures')->assertForbidden();
        $this->as($w, $principal)->postJson($this->base($w).'/ledger-accounts', ['code' => 'P1', 'name' => 'P', 'type' => 'asset'])->assertForbidden();
    }

    #[Test]
    public function case_variant_duplicate_codes_are_clean_422s(): void
    {
        $w = $this->feeWorld();
        $b = $this->base($w);
        $this->makeFeeHead($w, ['code' => 'TUITION']);
        $this->makeDraftStructure($w, ['code' => 'G5']);

        $this->as($w, $w['actor'])->postJson("{$b}/fee-heads", ['code' => 'tuition', 'name' => 'T', 'receivable_ledger_account_id' => $w['receivable']->id, 'revenue_ledger_account_id' => $w['revenue']->id])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['code']]]);
        $this->as($w, $w['actor'])->postJson("{$b}/fee-structures", ['academic_year_id' => $w['year']->id, 'grade_level_id' => $w['grade']->id, 'code' => 'g5', 'name' => 'G'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['code']]]);
        $this->as($w, $w['actor'])->postJson("{$b}/ledger-accounts", ['code' => 'ar-fees', 'name' => 'Dup', 'type' => 'asset'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['code']]]);
    }

    #[Test]
    public function domain_rejections_are_field_errors_and_conflicts_keep_their_codes(): void
    {
        $w = $this->feeWorld();
        $b = $this->base($w);
        $expense = $this->createLedgerAccount($w['school'], ['type' => 'expense']);

        $this->as($w, $w['actor'])->postJson("{$b}/fee-heads", ['code' => 'X', 'name' => 'X', 'receivable_ledger_account_id' => $expense->id, 'revenue_ledger_account_id' => $w['revenue']->id])
            ->assertStatus(422)->assertJsonStructure(['error' => ['errors' => ['receivable_ledger_account_id']]]);

        $draft = $this->makeDraftStructure($w);
        $this->as($w, $w['actor'])->postJson("{$b}/fee-structures/{$draft->id}/activate")
            ->assertStatus(422)->assertJsonPath('error.code', 'FEE_STRUCTURE_INCOMPLETE');

        $this->as($w, $w['actor'])->postJson("{$b}/fee-structures/{$draft->id}/retire")
            ->assertStatus(409)->assertJsonPath('error.code', 'FEE_STRUCTURE_ILLEGAL_TRANSITION');
    }

    #[Test]
    public function money_is_a_decimal_string_and_the_detail_exposes_no_school_id(): void
    {
        $w = $this->feeWorld();
        $structure = $this->makeCompleteDraft($w, [], '1500.5');

        $data = $this->as($w, $w['actor'])->getJson($this->base($w)."/fee-structures/{$structure->id}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('schoolId', $data);
        $this->assertSame('1500.50', $data['lines'][0]['amount']);
        $this->assertSame('INR', $data['lines'][0]['currency']);
        $this->assertSame('1500.50', $data['lines'][0]['installments'][0]['amount']);
        $this->assertIsString($data['lines'][0]['installments'][0]['amount']);
    }

    #[Test]
    public function another_schools_records_are_404s(): void
    {
        $a = $this->feeWorld();
        $b = $this->feeWorld();
        $foreignHead = $this->makeFeeHead($b);
        $foreignStructure = $this->makeCompleteDraft($b);

        $this->as($a, $a['actor'])->getJson($this->base($a)."/fee-heads/{$foreignHead->id}")->assertNotFound();
        $this->as($a, $a['actor'])->getJson($this->base($a)."/fee-structures/{$foreignStructure->id}")->assertNotFound();
        $this->as($a, $a['actor'])->postJson($this->base($a)."/fee-structures/{$foreignStructure->id}/activate")->assertNotFound();
        $this->as($a, $a['actor'])->patchJson($this->base($a)."/ledger-accounts/{$b['receivable']->id}", ['status' => 'inactive'])->assertNotFound();
    }

    #[Test]
    public function selection_listing_needs_a_narrowing_filter(): void
    {
        $w = $this->feeWorld();

        $this->as($w, $w['actor'])->getJson($this->base($w).'/fee-optional-selections')->assertStatus(422);
    }

    #[Test]
    public function no_delete_route_exists_for_any_permanent_fee_or_ledger_record(): void
    {
        $deletes = collect(Route::getRoutes())
            ->filter(fn ($r) => in_array('DELETE', $r->methods(), true))
            ->map(fn ($r) => $r->uri())
            ->filter(fn ($uri) => preg_match('#(fee-|ledger-accounts|fee_setup|fee-setup)#', $uri))
            ->values()
            ->all();

        $this->assertSame(['api/v1/schools/{school}/fee-structures/{feeStructure}/lines/{line}'], $deletes,
            'The only fee setup DELETE removes a DRAFT structure line.');
    }
}
