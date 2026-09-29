<?php

namespace Tests\Feature\Fees\Concerns;

use App\Domain\AcademicStructure\Infrastructure\Section;
use App\Domain\Fees\Application\FeeAssessmentRunService;
use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Infrastructure\FeeAssessmentRun;
use App\Domain\Fees\Infrastructure\FeeAssessmentRunItem;
use App\Domain\Fees\Infrastructure\FeeHead;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Students\Infrastructure\Student;
use App\Domain\Students\Infrastructure\StudentEnrollment;
use App\Models\Campus;
use Illuminate\Support\Collection;

/**
 * FEE.2 fixtures on top of the FEE.1 world: a Section, an ACTIVE structure
 * with a two-term custom schedule (T1 2026-06-01..2026-10-31 = 5000.00,
 * T2 2026-11-01..2027-05-31 = 7000.00, line 12000.00), enrolled Students,
 * and an actor holding every FEE.1/FEE.2 capability plus charge view/manage.
 */
trait CreatesFeeAssessmentFixtures
{
    use CreatesFeeSetupFixtures;

    protected function assessmentWorld(): array
    {
        $w = $this->feeWorld();
        $w['actor'] = $this->createUserWithCapabilities($w['school'], [
            ...self::FEE_SETUP_CAPABILITIES, 'finance.fee_assessments.run', 'finance.charges.view', 'finance.charges.manage',
        ]);
        $w['section'] = $this->createSection($w['year'], $w['campus'], $w['grade']);
        $w['head'] = $this->makeFeeHead($w);
        $w['structure'] = $this->activeTwoTermStructure($w, ['code' => 'G-DEF'], $w['head']);

        return $w;
    }

    protected function activeTwoTermStructure(array $w, array $attributes, FeeHead $head, bool $optional = false, ?FeeHead $second = null): FeeStructure
    {
        $service = app(FeeStructureService::class);
        $structure = $this->makeDraftStructure($w, $attributes);

        foreach (array_filter([$head, $second]) as $i => $h) {
            $line = $service->addLine($w['school'], $structure->id, ['fee_head_id' => $h->id, 'amount' => '12000.00', 'is_optional' => $optional && $i === 0], $w['actor']);
            $service->replaceInstallments($w['school'], $structure->id, $line->id, [
                ['label' => 'Term 1', 'billing_period_key' => 'T1', 'period_starts_on' => '2026-06-01', 'period_ends_on' => '2026-10-31', 'due_date' => '2026-06-10', 'amount' => '5000.00'],
                ['label' => 'Term 2', 'billing_period_key' => 'T2', 'period_starts_on' => '2026-11-01', 'period_ends_on' => '2027-05-31', 'due_date' => '2026-11-10', 'amount' => '7000.00'],
            ], $w['actor']);
        }

        return $service->activate($w['school'], $structure->id, $w['actor']);
    }

    protected function enroll(array $w, string $startsOn = '2026-06-01', array $attributes = [], ?Section $section = null, ?Student $student = null): StudentEnrollment
    {
        $student ??= $this->createStudent($w['school']);

        return $this->createStudentEnrollment($student, $section ?? $w['section'], array_merge(['starts_on' => $startsOn], $attributes));
    }

    protected function sectionIn(array $w, Campus $campus): Section
    {
        return $this->createSection($w['year'], $campus, $w['grade'], ['code' => 'S'.strtoupper(substr($campus->id, -5)), 'name' => 'Section']);
    }

    protected function runs(): FeeAssessmentRunService
    {
        return app(FeeAssessmentRunService::class);
    }

    protected function previewedRun(array $w, string $key = 'T1', ?FeeStructure $structure = null): FeeAssessmentRun
    {
        $run = $this->runs()->create($w['school'], ($structure ?? $w['structure'])->id, $key, $w['actor']);

        return $this->runs()->preview($w['school'], $run->id, $w['actor']);
    }

    /** Creates, previews and executes (the sync queue runs the job inline). */
    protected function executedRun(array $w, string $key = 'T1', ?FeeStructure $structure = null): FeeAssessmentRun
    {
        $run = $this->previewedRun($w, $key, $structure);
        $this->runs()->execute($w['school'], $run->id, $w['actor']);

        return $this->inSchool($w['school'], fn () => $run->refresh());
    }

    /** @return Collection<int, FeeAssessmentRunItem> */
    protected function items(array $w, FeeAssessmentRun $run): Collection
    {
        return $this->inSchool($w['school'], fn () => FeeAssessmentRunItem::query()->where('fee_assessment_run_id', $run->id)->get());
    }

    protected function itemFor(array $w, FeeAssessmentRun $run, string $studentId): FeeAssessmentRunItem
    {
        return $this->items($w, $run)->firstWhere('student_id', $studentId);
    }
}
