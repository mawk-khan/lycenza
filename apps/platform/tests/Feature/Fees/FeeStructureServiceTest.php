<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\Exceptions\DuplicateFeeStructureCodeException;
use App\Domain\Fees\Application\Exceptions\DuplicateFeeStructureLineException;
use App\Domain\Fees\Application\Exceptions\FeeStructureActivationConflictException;
use App\Domain\Fees\Application\Exceptions\FeeStructureIllegalTransitionException;
use App\Domain\Fees\Application\Exceptions\FeeStructureIncompleteException;
use App\Domain\Fees\Application\Exceptions\FeeStructureNotDraftException;
use App\Domain\Fees\Application\Exceptions\FeeStructureNotFoundException;
use App\Domain\Fees\Application\Exceptions\InvalidFeeStructureException;
use App\Domain\Fees\Application\FeeHeadService;
use App\Domain\Fees\Application\FeeStructureReadService;
use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Fees\Infrastructure\FeeStructureInstallment;
use App\Domain\Fees\Infrastructure\FeeStructureLine;
use App\Models\DomainEventOutbox;
use App\Models\SchoolAuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeSetupFixtures;
use Tests\TestCase;

/**
 * FEE.1 (ADR 0062 §7): structures (draft -> active -> retired), draft-only
 * lines and schedules, the authoritative instalment rows and their
 * generators, the activation sum guard, amendment by successor, and the
 * campus-override resolution.
 */
class FeeStructureServiceTest extends TestCase
{
    use CreatesFeeSetupFixtures;

    private function service(): FeeStructureService
    {
        return app(FeeStructureService::class);
    }

    private function line(FeeStructure $structure): FeeStructureLine
    {
        return $this->inSchool($structure->school, fn () => FeeStructureLine::query()->where('fee_structure_id', $structure->id)->firstOrFail());
    }

    /** @return list<FeeStructureInstallment> */
    private function schedule(FeeStructureLine $line): array
    {
        return $this->inSchool($line->school, fn () => FeeStructureInstallment::query()->where('fee_structure_line_id', $line->id)->orderBy('sequence')->get()->all());
    }

    // --- creation ---------------------------------------------------------------

    #[Test]
    public function a_draft_is_created_for_year_and_grade_with_optional_campus_and_audited(): void
    {
        $w = $this->feeWorld();

        $default = $this->makeDraftStructure($w, ['code' => 'g5']);
        $override = $this->makeDraftStructure($w, ['code' => 'G5-NORTH', 'campus_id' => $w['campus']->id]);

        $this->assertSame('G5', $default->code);
        $this->assertSame(FeeStructure::STATUS_DRAFT, $default->status);
        $this->assertNull($default->campus_id);
        $this->assertSame($w['campus']->id, $override->campus_id);
        $this->assertSame(2, $this->auditCount($w['school'], 'fee_structure.created'));
    }

    #[Test]
    public function no_section_scope_exists_anywhere_in_the_structure_model(): void
    {
        $this->assertNotContains('section_id', (new FeeStructure)->getFillable());
        $this->assertFalse(Schema::hasColumn('fee_structures', 'section_id'));
    }

    #[Test]
    public function a_closed_year_inactive_grade_or_foreign_campus_is_rejected(): void
    {
        $w = $this->feeWorld();
        $closed = $this->createAcademicYear($w['school'], ['code' => 'AY2020', 'starts_on' => '2020-06-01', 'ends_on' => '2021-05-31', 'status' => 'closed']);
        $inactiveGrade = $this->createGradeLevel($w['school'], ['status' => 'inactive']);
        $foreignCampus = $this->createCampus($this->createSchool());

        foreach ([
            'academic_year_id' => ['academic_year_id' => $closed->id],
            'grade_level_id' => ['grade_level_id' => $inactiveGrade->id],
            'campus_id' => ['campus_id' => $foreignCampus->id],
        ] as $field => $override) {
            try {
                $this->makeDraftStructure($w, ['code' => 'X-'.strtoupper(substr($field, 0, 4)), ...$override]);
                $this->fail("Expected {$field} rejection");
            } catch (InvalidFeeStructureException $e) {
                $this->assertSame($field, $e->field());
            }
        }
    }

    #[Test]
    public function a_case_variant_duplicate_code_in_the_same_year_is_a_typed_conflict(): void
    {
        $w = $this->feeWorld();
        $this->makeDraftStructure($w, ['code' => 'G5']);

        $this->expectException(DuplicateFeeStructureCodeException::class);
        $this->makeDraftStructure($w, ['code' => 'g5']);
    }

    // --- lines and schedules -----------------------------------------------------

    #[Test]
    public function amounts_are_exact_decimal_strings_and_never_floats(): void
    {
        $w = $this->feeWorld();
        $structure = $this->makeDraftStructure($w);
        $head = $this->makeFeeHead($w);

        $line = $this->service()->addLine($w['school'], $structure->id, ['fee_head_id' => $head->id, 'amount' => '1000'], $w['actor']);
        $this->assertSame('1000.00', $this->line($structure)->amount);

        foreach (['0', '0.00', '-5', '1.234', '1e3', '1234567890123.00'] as $bad) {
            try {
                $this->service()->updateLine($w['school'], $structure->id, $line->id, ['amount' => $bad], $w['actor']);
                $this->fail("Amount '{$bad}' must be rejected.");
            } catch (InvalidFeeStructureException) {
                $this->addToAssertionCount(1);
            }
        }

        try {
            $this->service()->updateLine($w['school'], $structure->id, $line->id, ['amount' => 1000.5], $w['actor']);
            $this->fail('A float amount must be rejected.');
        } catch (InvalidFeeStructureException) {
            $this->addToAssertionCount(1);
        }
    }

    #[Test]
    public function one_fee_head_per_structure_and_only_active_heads(): void
    {
        $w = $this->feeWorld();
        $structure = $this->makeDraftStructure($w);
        $head = $this->makeFeeHead($w);
        $this->service()->addLine($w['school'], $structure->id, ['fee_head_id' => $head->id, 'amount' => '500.00'], $w['actor']);

        try {
            $this->service()->addLine($w['school'], $structure->id, ['fee_head_id' => $head->id, 'amount' => '600.00'], $w['actor']);
            $this->fail('A second line for the same fee head must be refused.');
        } catch (DuplicateFeeStructureLineException) {
            $this->addToAssertionCount(1);
        }

        $inactive = $this->makeFeeHead($w, ['code' => 'OLD']);
        app(FeeHeadService::class)->deactivate($w['school'], $inactive->id, $w['actor']);

        $this->expectException(InvalidFeeStructureException::class);
        $this->service()->addLine($w['school'], $structure->id, ['fee_head_id' => $inactive->id, 'amount' => '600.00'], $w['actor']);
    }

    #[Test]
    public function the_monthly_generator_splits_the_amount_exactly_in_paise(): void
    {
        $w = $this->feeWorld();
        $structure = $this->makeDraftStructure($w);
        $head = $this->makeFeeHead($w);
        $line = $this->service()->addLine($w['school'], $structure->id, ['fee_head_id' => $head->id, 'amount' => '1000.00'], $w['actor']);

        $this->service()->generateInstallments($w['school'], $structure->id, $line->id, 'monthly', $w['actor']);

        $rows = $this->schedule($line);
        $this->assertCount(12, $rows);
        $this->assertSame('2026-06', $rows[0]->billing_period_key);
        $this->assertSame('2026-06-01', $rows[0]->period_starts_on->toDateString());
        $this->assertSame('2027-05-31', $rows[11]->period_ends_on->toDateString());
        $this->assertSame('83.34', $rows[0]->amount);
        $this->assertSame('83.33', $rows[11]->amount);
        $this->assertSame('1000.00', array_reduce($rows, fn ($c, $r) => bcadd($c, $r->amount, 2), '0.00'));
        $this->assertSame('monthly', $this->line($structure)->frequency);
    }

    #[Test]
    public function the_term_generator_uses_the_years_terms_and_one_time_uses_the_whole_year(): void
    {
        $w = $this->feeWorld();
        $this->createAcademicTerm($w['year'], ['code' => 't1', 'name' => 'Term 1', 'sequence' => 1, 'starts_on' => '2026-06-01', 'ends_on' => '2026-10-31']);
        $this->createAcademicTerm($w['year'], ['code' => 'T2', 'name' => 'Term 2', 'sequence' => 2, 'starts_on' => '2026-11-01', 'ends_on' => '2027-05-31']);
        $structure = $this->makeDraftStructure($w);
        $head = $this->makeFeeHead($w);
        $line = $this->service()->addLine($w['school'], $structure->id, ['fee_head_id' => $head->id, 'amount' => '999.99'], $w['actor']);

        $this->service()->generateInstallments($w['school'], $structure->id, $line->id, 'term', $w['actor']);
        $rows = $this->schedule($line);
        $this->assertSame(['T1', 'T2'], array_map(fn ($r) => $r->billing_period_key, $rows));
        $this->assertSame(['500.00', '499.99'], array_map(fn ($r) => $r->amount, $rows));
        $this->assertNotNull($rows[0]->academic_term_id);

        $this->service()->generateInstallments($w['school'], $structure->id, $line->id, 'one_time', $w['actor']);
        $rows = $this->schedule($line);
        $this->assertCount(1, $rows);
        $this->assertSame('ANNUAL', $rows[0]->billing_period_key);
        $this->assertSame('999.99', $rows[0]->amount);
    }

    #[Test]
    public function stored_rows_are_authoritative_and_validated_against_the_year(): void
    {
        $w = $this->feeWorld();
        $structure = $this->makeDraftStructure($w);
        $head = $this->makeFeeHead($w);
        $line = $this->service()->addLine($w['school'], $structure->id, ['fee_head_id' => $head->id, 'amount' => '300.00'], $w['actor']);

        $row = fn (array $o = []) => array_merge([
            'label' => 'Q1', 'billing_period_key' => 'q1', 'period_starts_on' => '2026-06-01',
            'period_ends_on' => '2026-08-31', 'due_date' => '2026-06-10', 'amount' => '100.00',
        ], $o);

        $this->service()->replaceInstallments($w['school'], $structure->id, $line->id, [
            $row(), $row(['label' => 'Q2', 'billing_period_key' => 'Q2', 'amount' => '200.00']),
        ], $w['actor']);
        $this->assertSame(['Q1', 'Q2'], array_map(fn ($r) => $r->billing_period_key, $this->schedule($line)));
        $this->assertSame('custom', $this->line($structure)->frequency);

        $invalid = [
            'duplicate key' => [$row(), $row(['billing_period_key' => 'Q1'])],
            'before year' => [$row(['period_starts_on' => '2026-05-01'])],
            'after year' => [$row(['period_ends_on' => '2027-06-30'])],
            'due before start' => [$row(['due_date' => '2026-05-31'])],
            'ends before starts' => [$row(['period_ends_on' => '2026-05-31'])],
            'bad date' => [$row(['due_date' => '2026-02-30'])],
            'bad key' => [$row(['billing_period_key' => '-x'])],
        ];

        foreach ($invalid as $label => $rows) {
            try {
                $this->service()->replaceInstallments($w['school'], $structure->id, $line->id, $rows, $w['actor']);
                $this->fail("Expected rejection: {$label}");
            } catch (InvalidFeeStructureException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertCount(2, $this->schedule($line), 'A rejected replacement leaves the previous schedule intact.');
    }

    #[Test]
    public function a_term_of_another_year_is_rejected(): void
    {
        $w = $this->feeWorld();
        $otherYear = $this->createAcademicYear($w['school'], ['code' => 'AY2027', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31']);
        $foreignTerm = $this->createAcademicTerm($otherYear, ['starts_on' => '2027-06-01', 'ends_on' => '2027-10-31']);
        $structure = $this->makeDraftStructure($w);
        $head = $this->makeFeeHead($w);
        $line = $this->service()->addLine($w['school'], $structure->id, ['fee_head_id' => $head->id, 'amount' => '100.00'], $w['actor']);

        $this->expectException(InvalidFeeStructureException::class);
        $this->service()->replaceInstallments($w['school'], $structure->id, $line->id, [[
            'label' => 'T', 'billing_period_key' => 'T', 'period_starts_on' => '2026-06-01', 'period_ends_on' => '2026-10-31',
            'due_date' => '2026-06-01', 'academic_term_id' => $foreignTerm->id, 'amount' => '100.00',
        ]], $w['actor']);
    }

    // --- activation ---------------------------------------------------------------

    #[Test]
    public function activation_requires_lines_and_exact_instalment_sums(): void
    {
        $w = $this->feeWorld();
        $empty = $this->makeDraftStructure($w, ['code' => 'EMPTY']);

        try {
            $this->service()->activate($w['school'], $empty->id, $w['actor']);
            $this->fail('A structure without lines must not activate.');
        } catch (FeeStructureIncompleteException) {
            $this->addToAssertionCount(1);
        }

        $structure = $this->makeDraftStructure($w, ['code' => 'SUM']);
        $head = $this->makeFeeHead($w);
        $line = $this->service()->addLine($w['school'], $structure->id, ['fee_head_id' => $head->id, 'amount' => '100.00'], $w['actor']);
        $this->service()->replaceInstallments($w['school'], $structure->id, $line->id, [[
            'label' => 'A', 'billing_period_key' => 'A', 'period_starts_on' => '2026-06-01', 'period_ends_on' => '2027-05-31',
            'due_date' => '2026-06-01', 'amount' => '99.99',
        ]], $w['actor']);

        try {
            $this->service()->activate($w['school'], $structure->id, $w['actor']);
            $this->fail('A schedule summing to 99.99 of 100.00 must not activate.');
        } catch (FeeStructureIncompleteException) {
            $this->addToAssertionCount(1);
        }

        $this->service()->updateLine($w['school'], $structure->id, $line->id, ['amount' => '99.99'], $w['actor']);
        $this->assertSame('active', $this->service()->activate($w['school'], $structure->id, $w['actor'])->status);
    }

    #[Test]
    public function activation_is_audited_once_and_emits_one_internal_outbox_event(): void
    {
        $w = $this->feeWorld();
        $structure = $this->makeCompleteDraft($w);

        $active = $this->service()->activate($w['school'], $structure->id, $w['actor']);

        $this->assertNotNull($active->activated_at);
        $this->assertSame($w['actor']->id, $active->activated_by_user_id);
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_structure.activated'));

        $events = DomainEventOutbox::query()->where('school_id', $w['school']->id)->where('event_type', 'fee_structure.activated.v1')->get();
        $this->assertCount(1, $events);
        $this->assertSame($structure->id, $events[0]->payload['feeStructureId']);
        $this->assertArrayNotHasKey('amount', $events[0]->payload);
    }

    #[Test]
    public function a_non_draft_structure_cannot_be_edited_or_reactivated(): void
    {
        $w = $this->feeWorld();
        $structure = $this->makeCompleteDraft($w);
        $line = $this->line($structure);
        $this->service()->activate($w['school'], $structure->id, $w['actor']);

        $writes = [
            fn () => $this->service()->updateDraft($w['school'], $structure->id, ['name' => 'Renamed'], $w['actor']),
            fn () => $this->service()->updateLine($w['school'], $structure->id, $line->id, ['amount' => '1.00'], $w['actor']),
            fn () => $this->service()->removeLine($w['school'], $structure->id, $line->id, $w['actor']),
            fn () => $this->service()->generateInstallments($w['school'], $structure->id, $line->id, 'monthly', $w['actor']),
        ];
        foreach ($writes as $i => $write) {
            try {
                $write();
                $this->fail("Write {$i} on an active structure must be refused.");
            } catch (FeeStructureNotDraftException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(FeeStructureIllegalTransitionException::class);
        $this->service()->activate($w['school'], $structure->id, $w['actor']);
    }

    #[Test]
    public function a_second_active_structure_for_the_same_scope_is_a_typed_conflict_but_a_campus_override_is_not(): void
    {
        $w = $this->feeWorld();
        $first = $this->makeCompleteDraft($w, ['code' => 'A']);
        $second = $this->makeCompleteDraft($w, ['code' => 'B']);
        $override = $this->makeCompleteDraft($w, ['code' => 'C', 'campus_id' => $w['campus']->id]);

        $this->service()->activate($w['school'], $first->id, $w['actor']);
        $this->assertSame('active', $this->service()->activate($w['school'], $override->id, $w['actor'])->status);

        try {
            $this->service()->activate($w['school'], $second->id, $w['actor']);
            $this->fail('A second School-default structure for the same year and grade must not activate.');
        } catch (FeeStructureActivationConflictException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame('draft', $this->inSchool($w['school'], fn () => FeeStructure::query()->findOrFail($second->id))->status);
    }

    #[Test]
    public function retirement_is_active_only_final_and_audited(): void
    {
        $w = $this->feeWorld();
        $structure = $this->makeCompleteDraft($w);

        try {
            $this->service()->retire($w['school'], $structure->id, $w['actor']);
            $this->fail('A draft cannot be retired.');
        } catch (FeeStructureIllegalTransitionException) {
            $this->addToAssertionCount(1);
        }

        $this->service()->activate($w['school'], $structure->id, $w['actor']);
        $retired = $this->service()->retire($w['school'], $structure->id, $w['actor']);

        $this->assertSame('retired', $retired->status);
        $this->assertSame($w['actor']->id, $retired->retired_by_user_id);
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_structure.retired'));

        $this->expectException(FeeStructureIllegalTransitionException::class);
        $this->service()->activate($w['school'], $structure->id, $w['actor']);
    }

    // --- amendment -------------------------------------------------------------------

    #[Test]
    public function a_successor_copies_the_schedule_and_its_activation_retires_the_predecessor_atomically(): void
    {
        $w = $this->feeWorld();
        $original = $this->makeCompleteDraft($w, ['code' => 'V1']);
        $this->service()->activate($w['school'], $original->id, $w['actor']);

        $successor = $this->service()->createSuccessor($w['school'], $original->id, ['code' => 'v2'], $w['actor']);

        $this->assertSame('V2', $successor->code);
        $this->assertSame($original->id, $successor->supersedes_fee_structure_id);
        $copiedLine = $this->line($successor);
        $this->assertSame('12000.00', $copiedLine->amount);
        $this->assertSame(['ANNUAL'], array_map(fn ($r) => $r->billing_period_key, $this->schedule($copiedLine)));

        $this->service()->updateLine($w['school'], $successor->id, $copiedLine->id, ['amount' => '13000.00'], $w['actor']);
        $this->service()->generateInstallments($w['school'], $successor->id, $copiedLine->id, 'one_time', $w['actor']);
        $this->service()->activate($w['school'], $successor->id, $w['actor']);

        $this->assertSame('retired', $this->inSchool($w['school'], fn () => FeeStructure::query()->findOrFail($original->id))->status);
        $this->assertSame('12000.00', $this->line($original)->amount, 'The predecessor\'s terms never change.');
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_structure.superseded'));
        $this->assertSame(0, $this->auditCount($w['school'], 'fee_structure.retired'));
    }

    #[Test]
    public function only_an_active_structure_can_be_amended_and_only_one_successor_ever_activates(): void
    {
        $w = $this->feeWorld();
        $draft = $this->makeCompleteDraft($w, ['code' => 'D']);

        try {
            $this->service()->createSuccessor($w['school'], $draft->id, ['code' => 'D2'], $w['actor']);
            $this->fail('A draft cannot be amended by a successor.');
        } catch (InvalidFeeStructureException) {
            $this->addToAssertionCount(1);
        }

        $this->service()->activate($w['school'], $draft->id, $w['actor']);
        $s1 = $this->service()->createSuccessor($w['school'], $draft->id, ['code' => 'S1'], $w['actor']);
        $s2 = $this->service()->createSuccessor($w['school'], $draft->id, ['code' => 'S2'], $w['actor']);

        $this->service()->activate($w['school'], $s1->id, $w['actor']);

        $this->expectException(FeeStructureActivationConflictException::class);
        $this->service()->activate($w['school'], $s2->id, $w['actor']);
    }

    // --- resolution and authorization -------------------------------------------------

    #[Test]
    public function the_campus_override_wins_over_the_school_default(): void
    {
        $w = $this->feeWorld();
        $otherCampus = $this->createCampus($w['school']);
        $default = $this->makeCompleteDraft($w, ['code' => 'DEF']);
        $override = $this->makeCompleteDraft($w, ['code' => 'OVR', 'campus_id' => $w['campus']->id]);
        $reads = app(FeeStructureReadService::class);

        $this->assertNull($reads->resolveActiveStructure($w['school'], $w['year']->id, $w['grade']->id, $w['campus']->id, $w['actor']));

        $this->service()->activate($w['school'], $default->id, $w['actor']);
        $this->assertSame($default->id, $reads->resolveActiveStructure($w['school'], $w['year']->id, $w['grade']->id, $w['campus']->id, $w['actor'])?->id);

        $this->service()->activate($w['school'], $override->id, $w['actor']);
        $this->assertSame($override->id, $reads->resolveActiveStructure($w['school'], $w['year']->id, $w['grade']->id, $w['campus']->id, $w['actor'])?->id);
        $this->assertSame($default->id, $reads->resolveActiveStructure($w['school'], $w['year']->id, $w['grade']->id, $otherCampus->id, $w['actor'])?->id);
        $this->assertSame($default->id, $reads->resolveActiveStructure($w['school'], $w['year']->id, $w['grade']->id, null, $w['actor'])?->id);
    }

    #[Test]
    public function every_write_requires_manage_and_reads_require_view(): void
    {
        $w = $this->feeWorld();
        $structure = $this->makeCompleteDraft($w);
        $viewer = $this->createUserWithCapabilities($w['school'], ['finance.fee_structures.view']);
        $nobody = $this->createUserWithCapabilities($w['school'], ['finance.charges.view']);
        $line = $this->line($structure);

        $writes = [
            fn () => $this->service()->createDraft($w['school'], ['academic_year_id' => $w['year']->id, 'grade_level_id' => $w['grade']->id, 'code' => 'Z', 'name' => 'Z'], $viewer),
            fn () => $this->service()->updateDraft($w['school'], $structure->id, ['name' => 'Z'], $viewer),
            fn () => $this->service()->addLine($w['school'], $structure->id, ['fee_head_id' => $line->fee_head_id, 'amount' => '1.00'], $viewer),
            fn () => $this->service()->updateLine($w['school'], $structure->id, $line->id, ['amount' => '1.00'], $viewer),
            fn () => $this->service()->removeLine($w['school'], $structure->id, $line->id, $viewer),
            fn () => $this->service()->replaceInstallments($w['school'], $structure->id, $line->id, [], $viewer),
            fn () => $this->service()->generateInstallments($w['school'], $structure->id, $line->id, 'monthly', $viewer),
            fn () => $this->service()->activate($w['school'], $structure->id, $viewer),
            fn () => $this->service()->retire($w['school'], $structure->id, $viewer),
            fn () => $this->service()->createSuccessor($w['school'], $structure->id, ['code' => 'Z'], $viewer),
        ];
        foreach ($writes as $i => $write) {
            try {
                $write();
                $this->fail("Write {$i} must be denied to a view-only actor.");
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $reads = app(FeeStructureReadService::class);
        $this->assertSame($structure->id, $reads->getStructure($w['school'], $structure->id, $viewer)->id);

        $this->expectException(AuthorizationException::class);
        $reads->listStructures($w['school'], [], $nobody);
    }

    #[Test]
    public function another_schools_structure_is_not_found(): void
    {
        $a = $this->feeWorld();
        $b = $this->feeWorld();
        $foreign = $this->makeCompleteDraft($b);

        $this->expectException(FeeStructureNotFoundException::class);
        $this->service()->activate($a['school'], $foreign->id, $a['actor']);
    }

    #[Test]
    public function draft_line_edits_write_one_updated_audit_each(): void
    {
        $w = $this->feeWorld();
        $structure = $this->makeCompleteDraft($w);

        // makeCompleteDraft = addLine + generateInstallments.
        $this->assertSame(2, $this->inSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('event_type', 'fee_structure.updated')->count()));
    }
}
