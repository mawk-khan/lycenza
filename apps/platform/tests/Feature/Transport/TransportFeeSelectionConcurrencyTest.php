<?php

namespace Tests\Feature\Transport;

use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Transport\Application\TransportFeeSelectionService;
use App\Domain\Transport\Application\TransportStudentAssignmentService;
use App\Domain\Transport\Infrastructure\TransportFeeSelection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * OPF.1 (ADR 0067 §10): Transport fee intent is recorded exactly once under
 * real concurrency -- two genuinely separate OS processes against real
 * PostgreSQL, the holder's writes held uncommitted until the contender is
 * observed blocked on them (ForcesConcurrentOverlap). Committed fixtures.
 */
class TransportFeeSelectionConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesFeeAssessmentFixtures, ForcesConcurrentOverlap;

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/transport-fee-op.php', ...$args];
    }

    /** @return array<string, mixed> a mapped route, an assigned enrolled Student and a draft next year with its structure */
    private function world(): array
    {
        $w = $this->feeWorld();
        $w['actor'] = $this->createUserWithCapabilities($w['school'], [...self::FEE_SETUP_CAPABILITIES, 'finance.fee_assessments.run']);
        $section = $this->createSection($w['year'], $w['campus'], $w['grade']);
        $w['transportHead'] = $this->makeFeeHead($w, ['code' => 'TRANSPORT', 'name' => 'Transport']);
        $this->activeTwoTermStructure($w, ['code' => 'G-DEF'], $w['transportHead'], true, $this->makeFeeHead($w));
        $w['route'] = $this->createTransportRoute($w['school']);
        app(TransportFeeSelectionService::class)->setRouteFeeHead($w['route'], $w['transportHead']->id, null);

        $w['student'] = $this->inSchool($w['school'], fn () => Student::query()->findOrFail($this->enroll($w, '2026-06-01', [], $section)->student_id));
        $w['assignment'] = app(TenantContext::class)->withSchool($w['school'], fn () => app(TransportStudentAssignmentService::class)->assign($w['student'], $w['route'], null, null, null));

        $w['next'] = $this->createAcademicYear($w['school'], ['code' => 'AY2027', 'name' => '2027-28', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31', 'status' => 'draft']);
        $nextSection = $this->createSection($w['next'], $w['campus'], $w['grade'], ['code' => 'NEXT', 'name' => 'Next']);
        $next = ['school' => $w['school'], 'actor' => $w['actor'], 'year' => $w['next'], 'grade' => $w['grade']];
        $this->activeTwoTermStructureFor($next, $w['transportHead']);
        $this->createStudentEnrollment($w['student'], $nextSection, ['starts_on' => '2027-06-01']);

        return $w;
    }

    /** @param  array<string, mixed>  $w */
    private function activeTwoTermStructureFor(array $w, $head): void
    {
        $service = app(FeeStructureService::class);
        $structure = $service->createDraft($w['school'], ['academic_year_id' => $w['year']->id, 'grade_level_id' => $w['grade']->id, 'campus_id' => null, 'code' => 'G-NEXT', 'name' => 'Next'], $w['actor']);
        $line = $service->addLine($w['school'], $structure->id, ['fee_head_id' => $head->id, 'amount' => '1000.00', 'is_optional' => true], $w['actor']);
        $service->replaceInstallments($w['school'], $structure->id, $line->id, [
            ['label' => 'Year', 'billing_period_key' => 'Y1', 'period_starts_on' => '2027-06-01', 'period_ends_on' => '2028-05-31', 'due_date' => '2027-06-10', 'amount' => '1000.00'],
        ], $w['actor']);
        $service->activate($w['school'], $structure->id, $w['actor']);
    }

    /** @param  array<string, mixed>  $w */
    private function rows(array $w, string $model, array $where): int
    {
        return $this->inSchool($w['school'], fn () => $model::query()->where($where)->count());
    }

    #[Test]
    public function two_concurrent_carry_forwards_record_one_selection_and_one_provenance_row(): void
    {
        $w = $this->world();
        $args = ['carry-forward', $w['school']->id, $w['next']->id];

        [$holder, $contender] = $this->raceWithHeldHolder($this->op(...$args), $this->op(...$args));

        $this->assertSame('linked:1 already_linked:0', $holder);
        $this->assertSame('linked:0 already_linked:1', $contender, 'the contender waited on the assignment lock, then found the committed link');
        $this->assertSame(1, $this->rows($w, TransportFeeSelection::class, ['transport_student_assignment_id' => $w['assignment']->id, 'academic_year_id' => $w['next']->id]));
        $this->assertSame(1, $this->rows($w, FeeOptionalSelection::class, ['student_id' => $w['student']->id, 'academic_year_id' => $w['next']->id, 'status' => 'active']));
    }

    #[Test]
    public function two_concurrent_source_selections_resolve_to_one_selection_and_never_surface_the_unique_race(): void
    {
        $w = $this->world();
        $args = ['select', $w['school']->id, $w['student']->id, $w['next']->id, $w['transportHead']->id, $w['assignment']->id];

        [$holder, $contender] = $this->raceWithHeldHolder($this->op(...$args), $this->op(...$args));

        [$holderOutcome, $selectionId] = explode(':', $holder);
        $this->assertSame('created', $holderOutcome, $holder);
        $this->assertSame("reused:{$selectionId}", $contender, 'the contender waited on the unique index, then reused the winner -- no error surfaced');
        $this->assertSame(1, $this->rows($w, FeeOptionalSelection::class, ['student_id' => $w['student']->id, 'academic_year_id' => $w['next']->id, 'status' => 'active']));
        $this->assertSame(0, (int) DB::connection('pgsql_admin')->table('charges')->where('student_id', $w['student']->id)->count(), 'intent only: no charge');
    }
}
