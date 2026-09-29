<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\Exceptions\FeeStructureActivationConflictException;
use App\Domain\Fees\Application\FeeStructureService;
use App\Domain\Fees\Infrastructure\FeeStructure;
use App\Domain\Fees\Infrastructure\FeeStructureInstallment;
use App\Domain\Fees\Infrastructure\FeeStructureLine;
use App\Models\School;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Fees\Concerns\CreatesFeeSetupFixtures;
use Tests\TestCase;

/**
 * FEE.1 (ADR 0062 §12): real two-process races against real PostgreSQL
 * with forced, verified overlap (never a sequential simulation, never
 * SQLite):
 *
 * - two drafts of the same scope activated concurrently -> exactly one
 *   active structure; the loser gets the typed conflict;
 * - two successors of one predecessor activated concurrently -> exactly
 *   one live successor; the predecessor is retired once;
 * - a RAW instalment insert racing an activation -> the database trigger's
 *   FOR SHARE parent lock serializes it and refuses the late child, so an
 *   active structure never ends up with instalments that do not sum.
 */
class FeeStructureConcurrencyTest extends TestCase
{
    use CreatesFeeSetupFixtures, ForcesConcurrentOverlap;

    protected $connectionsToTransact = [];

    private ?School $school = null;

    private string $activate = __DIR__.'/../../Support/activate-fee-structure.php';

    protected function tearDown(): void
    {
        $this->deleteSchoolAsAdmin($this->school);

        parent::tearDown();
    }

    #[Test]
    public function two_concurrent_activations_for_one_scope_leave_exactly_one_active_structure(): void
    {
        $w = $this->feeWorld();
        $this->school = $w['school'];
        $a = $this->makeCompleteDraft($w, ['code' => 'A']);
        $b = $this->makeCompleteDraft($w, ['code' => 'B']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->activate, $w['school']->id, $a->id, $w['actor']->id],
            ['php', $this->activate, $w['school']->id, $b->id, $w['actor']->id],
        );

        $this->assertSame('activated', $holder);
        $this->assertSame('rejected:'.FeeStructureActivationConflictException::class, $contender);
        $this->assertSame(1, $this->inSchool($w['school'], fn () => FeeStructure::query()->where('status', 'active')->count()));
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_structure.activated'));
    }

    #[Test]
    public function two_concurrent_successor_activations_leave_exactly_one_live_successor(): void
    {
        $w = $this->feeWorld();
        $this->school = $w['school'];
        $original = $this->makeCompleteDraft($w, ['code' => 'V1']);
        app(FeeStructureService::class)->activate($w['school'], $original->id, $w['actor']);
        $s1 = app(FeeStructureService::class)->createSuccessor($w['school'], $original->id, ['code' => 'S1'], $w['actor']);
        $s2 = app(FeeStructureService::class)->createSuccessor($w['school'], $original->id, ['code' => 'S2'], $w['actor']);

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->activate, $w['school']->id, $s1->id, $w['actor']->id],
            ['php', $this->activate, $w['school']->id, $s2->id, $w['actor']->id],
        );

        $this->assertSame('activated', $holder);
        $this->assertSame('rejected:'.FeeStructureActivationConflictException::class, $contender);

        $statuses = $this->inSchool($w['school'], fn () => FeeStructure::query()->pluck('status', 'code')->all());
        $this->assertEquals(['V1' => 'retired', 'S1' => 'active', 'S2' => 'draft'], $statuses);
        $this->assertSame(1, $this->auditCount($w['school'], 'fee_structure.superseded'));
    }

    #[Test]
    public function a_raw_child_insert_racing_activation_is_refused_by_the_trigger_lock(): void
    {
        $w = $this->feeWorld();
        $this->school = $w['school'];
        $structure = $this->makeCompleteDraft($w, ['code' => 'LOCK']);
        $line = $this->inSchool($w['school'], fn () => FeeStructureLine::query()->where('fee_structure_id', $structure->id)->firstOrFail());

        [$holder, $contender] = $this->raceWithHeldHolder(
            ['php', $this->activate, $w['school']->id, $structure->id, $w['actor']->id],
            ['php', __DIR__.'/../../Support/insert-fee-installment.php', $w['school']->id, $line->id],
        );

        $this->assertSame('activated', $holder);
        $this->assertSame('rejected:immutable', $contender, 'The late child must wait for the activation and then be refused.');
        $this->assertSame(1, $this->inSchool($w['school'], fn () => FeeStructureInstallment::query()->where('fee_structure_line_id', $line->id)->count()));
    }
}
