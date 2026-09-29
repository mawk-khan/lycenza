<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\Exceptions\DuplicateFeeOptionalSelectionException;
use App\Domain\Fees\Application\Exceptions\FeeOptionalSelectionNotActiveException;
use App\Domain\Fees\Application\Exceptions\FeeOptionalSelectionNotFoundException;
use App\Domain\Fees\Application\Exceptions\FeeStructureLineHasSelectionsException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeOptionalSelectionException;
use App\Domain\Fees\Application\FeeOptionalSelectionService;
use App\Domain\Fees\Application\FeeStructureReadService;
use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Fees\Infrastructure\FeeStructureLine;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeSetupFixtures;
use Tests\TestCase;

/**
 * FEE.1 (ADR 0062 §8, decision E): optional fees need an explicit, active
 * selection per Student x AcademicYear x fee head; withdrawal is final and
 * only affects future assessment; a successor structure keeps selections.
 */
class FeeOptionalSelectionServiceTest extends TestCase
{
    use CreatesFeeSetupFixtures;

    private function service(): FeeOptionalSelectionService
    {
        return app(FeeOptionalSelectionService::class);
    }

    /** @return array{0: FeeStructure, 1: FeeStructureLine} */
    private function structureWithOptionalLine(array $w): array
    {
        $structure = $this->makeDraftStructure($w);
        $transport = $this->makeFeeHead($w, ['code' => 'TRANSPORT', 'name' => 'Transport']);
        $line = app(FeeStructureService::class)->addLine($w['school'], $structure->id, [
            'fee_head_id' => $transport->id, 'amount' => '6000.00', 'is_optional' => true,
        ], $w['actor']);

        return [$structure, $line];
    }

    #[Test]
    public function a_student_selects_an_optional_line_and_it_is_keyed_by_year_and_fee_head(): void
    {
        $w = $this->feeWorld();
        [$structure, $line] = $this->structureWithOptionalLine($w);
        $student = $this->createStudent($w['school']);

        $selection = $this->service()->select($w['school'], $student->id, $line->id, $w['actor']);

        $this->assertSame('active', $selection->status);
        $this->assertSame($w['year']->id, $selection->academic_year_id);
        $this->assertSame($line->fee_head_id, $selection->fee_head_id);
        $this->assertSame($w['actor']->id, $selection->selected_by_user_id);
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_optional_selection.created'));
    }

    #[Test]
    public function a_required_line_cannot_be_selected(): void
    {
        $w = $this->feeWorld();
        $structure = $this->makeCompleteDraft($w);
        $required = $this->inSchool($w['school'], fn () => FeeStructureLine::query()->where('fee_structure_id', $structure->id)->firstOrFail());

        $this->expectException(InvalidFeeOptionalSelectionException::class);
        $this->service()->select($w['school'], $this->createStudent($w['school'])->id, $required->id, $w['actor']);
    }

    #[Test]
    public function a_second_active_selection_for_the_same_student_year_and_head_is_a_typed_conflict(): void
    {
        $w = $this->feeWorld();
        [, $line] = $this->structureWithOptionalLine($w);
        $student = $this->createStudent($w['school']);
        $this->service()->select($w['school'], $student->id, $line->id, $w['actor']);

        $this->expectException(DuplicateFeeOptionalSelectionException::class);
        $this->service()->select($w['school'], $student->id, $line->id, $w['actor']);
    }

    #[Test]
    public function withdrawal_is_final_audited_and_allows_a_fresh_selection(): void
    {
        $w = $this->feeWorld();
        [, $line] = $this->structureWithOptionalLine($w);
        $student = $this->createStudent($w['school']);
        $first = $this->service()->select($w['school'], $student->id, $line->id, $w['actor']);

        $withdrawn = $this->service()->withdraw($w['school'], $first->id, $w['actor']);
        $this->assertSame('withdrawn', $withdrawn->status);
        $this->assertNotNull($withdrawn->withdrawn_at);
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_optional_selection.withdrawn'));

        try {
            $this->service()->withdraw($w['school'], $first->id, $w['actor']);
            $this->fail('A withdrawn selection cannot be withdrawn again.');
        } catch (FeeOptionalSelectionNotActiveException) {
            $this->addToAssertionCount(1);
        }

        $again = $this->service()->select($w['school'], $student->id, $line->id, $w['actor']);
        $this->assertNotSame($first->id, $again->id, 'Re-selecting creates a new row; the withdrawn one stays as history.');
        $this->assertSame(2, $this->inSchool($w['school'], fn () => FeeOptionalSelection::query()->where('student_id', $student->id)->count()));
    }

    #[Test]
    public function an_unknown_or_foreign_student_is_rejected(): void
    {
        $w = $this->feeWorld();
        [, $line] = $this->structureWithOptionalLine($w);
        $foreignStudent = $this->createStudent($this->createSchool());

        $this->expectException(InvalidFeeOptionalSelectionException::class);
        $this->service()->select($w['school'], $foreignStudent->id, $line->id, $w['actor']);
    }

    #[Test]
    public function a_draft_line_with_selections_cannot_be_removed(): void
    {
        $w = $this->feeWorld();
        [$structure, $line] = $this->structureWithOptionalLine($w);
        $this->service()->select($w['school'], $this->createStudent($w['school'])->id, $line->id, $w['actor']);

        $this->expectException(FeeStructureLineHasSelectionsException::class);
        app(FeeStructureService::class)->removeLine($w['school'], $structure->id, $line->id, $w['actor']);
    }

    #[Test]
    public function a_successor_structure_keeps_the_selection_because_it_is_keyed_by_fee_head(): void
    {
        $w = $this->feeWorld();
        [$structure, $line] = $this->structureWithOptionalLine($w);
        app(FeeStructureService::class)->generateInstallments($w['school'], $structure->id, $line->id, 'one_time', $w['actor']);
        app(FeeStructureService::class)->activate($w['school'], $structure->id, $w['actor']);
        $student = $this->createStudent($w['school']);
        $this->service()->select($w['school'], $student->id, $line->id, $w['actor']);

        $successor = app(FeeStructureService::class)->createSuccessor($w['school'], $structure->id, ['code' => 'V2'], $w['actor']);
        $successorLine = $this->inSchool($w['school'], fn () => FeeStructureLine::query()->where('fee_structure_id', $successor->id)->firstOrFail());
        app(FeeStructureService::class)->activate($w['school'], $successor->id, $w['actor']);

        $active = $this->inSchool($w['school'], fn () => FeeOptionalSelection::query()
            ->where('student_id', $student->id)->where('status', 'active')->sole());
        $this->assertSame($successorLine->fee_head_id, $active->fee_head_id);
        $this->assertSame($w['year']->id, $active->academic_year_id);
    }

    #[Test]
    public function writes_need_manage_and_listing_needs_view_plus_a_narrowing_filter(): void
    {
        $w = $this->feeWorld();
        [, $line] = $this->structureWithOptionalLine($w);
        $student = $this->createStudent($w['school']);
        $viewer = $this->createUserWithCapabilities($w['school'], ['finance.fee_structures.view']);

        try {
            $this->service()->select($w['school'], $student->id, $line->id, $viewer);
            $this->fail('Selecting must require finance.fee_structures.manage.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $selection = $this->service()->select($w['school'], $student->id, $line->id, $w['actor']);

        try {
            $this->service()->withdraw($w['school'], $selection->id, $viewer);
            $this->fail('Withdrawing must require finance.fee_structures.manage.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        $reads = app(FeeStructureReadService::class);
        $this->assertCount(1, $reads->listSelections($w['school'], ['student_id' => $student->id], $viewer));
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_optional_selection.list_viewed'));

        try {
            $reads->listSelections($w['school'], [], $viewer);
            $this->fail('An unfiltered listing must be refused.');
        } catch (InvalidFeeOptionalSelectionException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function another_schools_selection_is_not_found(): void
    {
        $a = $this->feeWorld();
        $b = $this->feeWorld();
        [, $line] = $this->structureWithOptionalLine($b);
        $foreign = $this->service()->select($b['school'], $this->createStudent($b['school'])->id, $line->id, $b['actor']);

        $this->expectException(FeeOptionalSelectionNotFoundException::class);
        $this->service()->withdraw($a['school'], $foreign->id, $a['actor']);
    }
}
