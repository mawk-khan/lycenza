<?php

namespace Tests\Feature\Hostel;

use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeOptionalSelection;
use App\Domain\Hostel\Application\HostelFeeSelectionService;
use App\Domain\Hostel\Application\HostelResidencyService;
use App\Domain\Hostel\Infrastructure\HostelFeeSelection;
use App\Domain\Students\Infrastructure\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * OPF.2 (ADR 0067 §10, §28): Hostel fee intent is recorded exactly once
 * under real concurrency -- two genuinely separate OS processes against real
 * PostgreSQL, the holder's writes held uncommitted until the contender is
 * observed blocked on them (ForcesConcurrentOverlap). Committed fixtures.
 */
class HostelFeeSelectionConcurrencyTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesFeeAssessmentFixtures, ForcesConcurrentOverlap;

    /** @return list<string> */
    private function op(string ...$args): array
    {
        return ['php', __DIR__.'/../../Support/hostel-fee-op.php', ...$args];
    }

    /**
     * An enrolled Student resident in a Hostel whose default tier is mapped
     * only AFTER the residency started (so its link is still unrecorded), and
     * a draft next year with its structure and the Student enrolled.
     *
     * @return array<string, mixed>
     */
    private function world(): array
    {
        $w = $this->feeWorld();
        $w['actor'] = $this->createUserWithCapabilities($w['school'], [...self::FEE_SETUP_CAPABILITIES, 'finance.fee_assessments.run']);
        $section = $this->createSection($w['year'], $w['campus'], $w['grade']);
        $w['hostelHead'] = $this->makeFeeHead($w, ['code' => 'HOSTEL', 'name' => 'Hostel']);
        $this->activeTwoTermStructure($w, ['code' => 'G-DEF'], $w['hostelHead'], true, $this->makeFeeHead($w));
        $hostel = $this->createHostel($w['school'], $w['campus']);
        $bed = $this->createHostelBed($this->createHostelRoom($hostel, ['code' => 'R-1']), ['code' => 'B-1']);

        $w['student'] = $this->inSchool($w['school'], fn () => Student::query()->findOrFail($this->enroll($w, '2026-06-01', [], $section)->student_id));
        $w['residency'] = app(TenantContext::class)->withSchool($w['school'], fn () => app(HostelResidencyService::class)->assign($w['student'], $bed, null));
        app(HostelFeeSelectionService::class)->setHostelFeeHead($hostel, $w['hostelHead']->id, null);

        $w['next'] = $this->createAcademicYear($w['school'], ['code' => 'AY2027', 'name' => '2027-28', 'starts_on' => '2027-06-01', 'ends_on' => '2028-05-31', 'status' => 'draft']);
        $nextSection = $this->createSection($w['next'], $w['campus'], $w['grade'], ['code' => 'NEXT', 'name' => 'Next']);
        $this->activeNextYearStructure(['school' => $w['school'], 'actor' => $w['actor'], 'year' => $w['next'], 'grade' => $w['grade']], $w['hostelHead']);
        $this->createStudentEnrollment($w['student'], $nextSection, ['starts_on' => '2027-06-01']);

        return $w;
    }

    /** @param  array<string, mixed>  $w */
    private function activeNextYearStructure(array $w, FeeHead $head): void
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
    public function a_residency_link_racing_a_carry_forward_into_the_same_year_records_one_selection_and_one_provenance_row(): void
    {
        $w = $this->world();
        $this->assertSame(0, $this->rows($w, HostelFeeSelection::class, ['hostel_residency_assignment_id' => $w['residency']->id]), 'mapped after the residency started');

        [$holder, $contender] = $this->raceWithHeldHolder(
            $this->op('link', $w['school']->id, $w['residency']->id),
            $this->op('carry-forward', $w['school']->id, $w['year']->id),
        );

        $this->assertSame('links:1', $holder);
        $this->assertSame('linked:0 already_linked:1', $contender, 'the carry-forward waited on the residency lock, then found the committed link');
        $this->assertSame(1, $this->rows($w, HostelFeeSelection::class, ['hostel_residency_assignment_id' => $w['residency']->id, 'academic_year_id' => $w['year']->id]));
        $this->assertSame(1, $this->rows($w, FeeOptionalSelection::class, ['student_id' => $w['student']->id, 'academic_year_id' => $w['year']->id, 'status' => 'active']));
    }

    #[Test]
    public function two_concurrent_carry_forwards_record_one_selection_and_one_provenance_row(): void
    {
        $w = $this->world();
        $args = ['carry-forward', $w['school']->id, $w['next']->id];

        [$holder, $contender] = $this->raceWithHeldHolder($this->op(...$args), $this->op(...$args));

        $this->assertSame('linked:1 already_linked:0', $holder);
        $this->assertSame('linked:0 already_linked:1', $contender, 'the contender waited on the residency lock, then found the committed link');
        $this->assertSame(1, $this->rows($w, HostelFeeSelection::class, ['hostel_residency_assignment_id' => $w['residency']->id, 'academic_year_id' => $w['next']->id]));
        $this->assertSame(1, $this->rows($w, FeeOptionalSelection::class, ['student_id' => $w['student']->id, 'academic_year_id' => $w['next']->id, 'status' => 'active']));
    }

    #[Test]
    public function two_concurrent_hostel_source_selections_resolve_to_one_selection_and_never_surface_the_unique_race(): void
    {
        $w = $this->world();
        $args = ['select', $w['school']->id, $w['student']->id, $w['next']->id, $w['hostelHead']->id, $w['residency']->id];

        [$holder, $contender] = $this->raceWithHeldHolder($this->op(...$args), $this->op(...$args));

        [$holderOutcome, $selectionId] = explode(':', $holder);
        $this->assertSame('created', $holderOutcome, $holder);
        $this->assertSame("reused:{$selectionId}", $contender, 'the contender waited on the unique index, then reused the winner -- no error surfaced');
        $this->assertSame(1, $this->rows($w, FeeOptionalSelection::class, ['student_id' => $w['student']->id, 'academic_year_id' => $w['next']->id, 'status' => 'active']));
        $this->assertSame(0, (int) DB::connection('pgsql_admin')->table('charges')->where('student_id', $w['student']->id)->count(), 'intent only: no charge');
    }
}
