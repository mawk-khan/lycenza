<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\FeeAssessmentPlacementCancellationBlocker;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * SIS 4.2: a placement with a live fee assessment (a Charge raised for it) is
 * not "never effective"; the blocker reports it until the assessment is voided
 * through Finance, and never touches it.
 */
class FeeAssessmentPlacementCancellationBlockerTest extends TestCase
{
    use CreatesFeeAssessmentFixtures;

    #[Test]
    public function a_live_fee_assessment_blocks_and_another_placement_is_not_blocked(): void
    {
        $w = $this->assessmentWorld();
        $billed = $this->enroll($w);
        $blocker = app(FeeAssessmentPlacementCancellationBlocker::class);
        $blocks = fn (string $enrollmentId) => $this->inSchool($w['school'], fn () => $blocker->blocksCancellation($w['school'], $enrollmentId));

        $this->assertFalse($blocks($billed->id), 'nothing billed yet');
        $this->executedRun($w);

        $this->assertTrue($blocks($billed->id));
        $this->assertFalse($blocks($this->enroll($w, '2026-06-02', [], null, $this->createStudent($w['school']))->id));
    }
}
