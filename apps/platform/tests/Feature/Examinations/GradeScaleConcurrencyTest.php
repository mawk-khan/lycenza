<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Application\Exceptions\GradeBandNotMutableException;
use App\Domain\Examinations\Application\Exceptions\GradeScaleIllegalTransitionException;
use App\Domain\Examinations\Application\Exceptions\GradeScaleIncompleteException;
use App\Domain\Examinations\Infrastructure\GradeBand;
use App\Domain\Examinations\Infrastructure\GradeScale;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;
use Tests\Feature\Examinations\Concerns\CreatesGradeScaleFixtures;
use Tests\TestCase;

/**
 * REQUIRED real-concurrency proof (Phase 0H.4C §19): two genuinely
 * separate OS processes race against real PostgreSQL — never two
 * sequential calls inside one PHP process. The parent-row
 * `lockForUpdate()` inside GradeScaleService (not a School-wide
 * TenantLock — see the Architecture Correction Gate) is what makes
 * both scenarios below safe.
 *
 * Deliberately does not use DatabaseTransactions
 * ($connectionsToTransact = []): the subprocesses are separate
 * PostgreSQL sessions and must see this test's committed fixtures,
 * mirroring CurriculumDeliveryTransitionConcurrencyTest's identical
 * reasoning.
 */
class GradeScaleConcurrencyTest extends TestCase
{
    use CreatesGradeScaleFixtures;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->school->delete();
        }

        parent::tearDown();
    }

    /**
     * Scenario A — activation vs. deletion of the 0.00 band.
     *
     * Only valid outcomes: B wins (band removed first; the scale
     * stays draft and A is refused as incomplete) or A wins
     * (activated first; the scale is active and B's removal is
     * refused because the scale is no longer draft). The impossible
     * outcome — an active Scale with no 0.00 band — is asserted away
     * explicitly at the end regardless of which side won.
     */
    #[Test]
    public function activation_versus_concurrent_removal_of_the_floor_band_preserves_the_invariant(): void
    {
        $w = $this->gradeScaleWorld();
        $this->school = $w['school'];

        $scale = $this->createGradeScale($w['school']);
        $floorBand = $this->createGradeBand($scale, ['min_percentage' => '0.00', 'label' => 'F']);
        $this->createGradeBand($scale, ['min_percentage' => '50.00', 'label' => 'P']);

        $activateScript = __DIR__.'/../../Support/activate-grade-scale.php';
        $removeScript = __DIR__.'/../../Support/remove-grade-band.php';

        $processA = new Process(['php', $activateScript, $w['school']->id, $scale->id, $w['actor']->id]);
        $processB = new Process(['php', $removeScript, $w['school']->id, $scale->id, $floorBand->id, $w['actor']->id]);
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = ['A' => $processA->getOutput(), 'B' => $processB->getOutput()];

        $final = $this->inGradeScaleSchool($w['school'], fn () => GradeScale::query()->findOrFail($scale->id));
        $floorStillExists = $this->inGradeScaleSchool(
            $w['school'],
            fn () => GradeBand::query()
                ->where('grade_scale_id', $scale->id)
                ->where('min_percentage', '0.00')
                ->exists(),
        );

        if ($final->status === 'active') {
            $this->assertSame(
                'rejected:'.GradeBandNotMutableException::class,
                $outputs['B'],
                'If activation won, the concurrent floor-band removal must be refused because the scale is no longer draft. Outputs: '.json_encode($outputs),
            );
            $this->assertTrue($floorStillExists, 'An active scale must never end up without its floor band.');
        } else {
            $this->assertSame('draft', $final->status);
            $this->assertSame(
                'rejected:'.GradeScaleIncompleteException::class,
                $outputs['A'],
                'If the floor band was removed first, activation must be refused for incompleteness. Outputs: '.json_encode($outputs),
            );
            $this->assertFalse($floorStillExists);
        }

        $this->assertFalse(
            $final->status === 'active' && ! $floorStillExists,
            'Impossible state: an active GradeScale must never end up without a 0.00 GradeBand.',
        );
    }

    /**
     * Scenario B — two concurrent activation attempts on the same
     * draft Scale. Exactly one must succeed; the other must observe
     * the now-active Scale and be refused as an illegal/no-op
     * transition. Exactly one activation audit event may exist
     * afterward.
     */
    #[Test]
    public function two_concurrent_activation_attempts_leave_exactly_one_winner(): void
    {
        $w = $this->gradeScaleWorld();
        $this->school = $w['school'];

        $scale = $this->createGradeScale($w['school']);
        $this->createGradeBand($scale, ['min_percentage' => '0.00', 'label' => 'F']);

        $script = __DIR__.'/../../Support/activate-grade-scale.php';
        $args = [$w['school']->id, $scale->id, $w['actor']->id];

        $processA = new Process(array_merge(['php', $script], $args));
        $processB = new Process(array_merge(['php', $script], $args));
        $processA->start();
        $processB->start();
        $processA->wait();
        $processB->wait();

        $outputs = [$processA->getOutput(), $processB->getOutput()];

        $activated = array_filter($outputs, fn ($o) => $o === 'activated:active');
        $refused = array_filter($outputs, fn ($o) => $o === 'rejected:'.GradeScaleIllegalTransitionException::class);

        $this->assertCount(
            1,
            $activated,
            'Exactly one of the two concurrent activations must succeed. Outputs: '.implode(' | ', $outputs),
        );
        $this->assertCount(
            1,
            $refused,
            'The loser must be refused as an illegal/no-op transition, not by an unrelated error. Outputs: '.implode(' | ', $outputs),
        );

        $final = $this->inGradeScaleSchool($w['school'], fn () => GradeScale::query()->findOrFail($scale->id));
        $this->assertSame('active', $final->status);

        $activatedAuditCount = $this->inGradeScaleSchool(
            $w['school'],
            fn () => SchoolAuditEvent::query()
                ->where('event_type', 'examinations.grade_scale.activated')
                ->get()
                ->filter(fn ($event) => ($event->metadata['scaleId'] ?? null) === $scale->id)
                ->count(),
        );
        $this->assertSame(1, $activatedAuditCount, 'Exactly one activation audit event must be created.');
    }
}
