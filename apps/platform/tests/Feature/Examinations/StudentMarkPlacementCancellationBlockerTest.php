<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Application\Marks\StudentMarkPlacementCancellationBlocker;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesStudentMarkFixtures;
use Tests\TestCase;

/**
 * SIS 4.2: a placement a mark was recorded against is academic history, never
 * "never effective"; the blocker reports it and never touches the mark.
 */
class StudentMarkPlacementCancellationBlockerTest extends TestCase
{
    use CreatesStudentMarkFixtures;

    #[Test]
    public function a_recorded_mark_blocks_its_placement_only(): void
    {
        $w = $this->marksWorld();
        $marked = $this->markStudent($w);
        $unmarked = $this->markStudent($w);
        $blocker = app(StudentMarkPlacementCancellationBlocker::class);
        $blocks = fn ($student) => $this->inMarksSchool($w['school'], fn () => $blocker->blocksCancellation($w['school'], $this->placementOf($w, $student)->id));

        $this->assertFalse($blocks($marked));
        $this->recordMarks($w, [$this->entry($marked, 'present', '20')]);

        $this->assertTrue($blocks($marked));
        $this->assertFalse($blocks($unmarked));
    }
}
