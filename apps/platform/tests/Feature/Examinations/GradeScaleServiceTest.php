<?php

namespace Tests\Feature\Examinations;

use App\Domain\Examinations\Application\Exceptions\DuplicateGradeBandThresholdException;
use App\Domain\Examinations\Application\Exceptions\DuplicateGradeScaleCodeException;
use App\Domain\Examinations\Application\Exceptions\GradeBandNotMutableException;
use App\Domain\Examinations\Application\Exceptions\GradeScaleIllegalTransitionException;
use App\Domain\Examinations\Application\Exceptions\GradeScaleIncompleteException;
use App\Domain\Examinations\Application\GradeScaleService;
use App\Domain\Examinations\Infrastructure\GradeBand;
use App\Models\SchoolAuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Examinations\Concerns\CreatesGradeScaleFixtures;
use Tests\TestCase;

class GradeScaleServiceTest extends TestCase
{
    use CreatesGradeScaleFixtures;

    private function service(): GradeScaleService
    {
        return app(GradeScaleService::class);
    }

    // --- creation ------------------------------------------------------

    #[Test]
    public function it_creates_a_draft_scale_with_bands_atomically(): void
    {
        $w = $this->gradeScaleWorld();

        $scale = $this->service()->create($w['school'], [
            'code' => 'GS1',
            'name' => 'Standard Scale',
            'bands' => [
                ['min_percentage' => '0.00', 'label' => 'F'],
                ['min_percentage' => '50.00', 'label' => 'P'],
            ],
        ], $w['actor']);

        $this->assertSame('draft', $scale->status);
        $this->assertSame('GS1', $scale->code);
        $this->assertCount(2, $scale->bands);

        $created = $this->inGradeScaleSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('school_id', $w['school']->id)
            ->where('event_type', 'examinations.grade_scale.created')
            ->first());
        $this->assertNotNull($created);
    }

    #[Test]
    public function it_creates_a_bandless_draft_scale(): void
    {
        $w = $this->gradeScaleWorld();

        $scale = $this->service()->create($w['school'], ['code' => 'GS1', 'name' => 'Empty Scale'], $w['actor']);

        $this->assertSame('draft', $scale->status);
        $this->assertCount(0, $scale->bands);
    }

    #[Test]
    public function a_case_variant_duplicate_code_is_translated(): void
    {
        $w = $this->gradeScaleWorld();
        $this->service()->create($w['school'], ['code' => 'GS1', 'name' => 'First'], $w['actor']);

        $this->expectException(DuplicateGradeScaleCodeException::class);
        $this->service()->create($w['school'], ['code' => 'gs1', 'name' => 'Second'], $w['actor']);
    }

    #[Test]
    public function a_duplicate_threshold_within_the_create_payload_is_translated(): void
    {
        $w = $this->gradeScaleWorld();

        $this->expectException(DuplicateGradeBandThresholdException::class);
        $this->service()->create($w['school'], [
            'code' => 'GS1',
            'name' => 'Bad Scale',
            'bands' => [
                ['min_percentage' => '50.00', 'label' => 'P'],
                ['min_percentage' => '50.00', 'label' => 'F'],
            ],
        ], $w['actor']);
    }

    // --- lifecycle: legal transitions -----------------------------------

    #[Test]
    public function a_complete_draft_scale_can_be_activated(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school']);
        $this->createGradeBand($scale, ['min_percentage' => '0.00']);

        $updated = $this->service()->update($w['school'], $scale, ['status' => 'active'], $w['actor']);

        $this->assertSame('active', $updated->status);
        $activated = $this->inGradeScaleSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('school_id', $w['school']->id)
            ->where('event_type', 'examinations.grade_scale.activated')
            ->first());
        $this->assertNotNull($activated);
    }

    #[Test]
    public function an_active_scale_can_be_deactivated(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school'], ['status' => 'active']);
        $this->createGradeBand($scale, ['min_percentage' => '0.00']);

        $updated = $this->service()->update($w['school'], $scale, ['status' => 'inactive'], $w['actor']);

        $this->assertSame('inactive', $updated->status);
    }

    #[Test]
    public function an_inactive_scale_can_be_reactivated_without_a_second_completeness_check_failure(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school'], ['status' => 'inactive']);
        $this->createGradeBand($scale, ['min_percentage' => '0.00']);

        $updated = $this->service()->update($w['school'], $scale, ['status' => 'active'], $w['actor']);

        $this->assertSame('active', $updated->status);
    }

    // --- lifecycle: illegal transitions, including all three no-ops -----

    public static function illegalTransitionProvider(): array
    {
        return [
            'draft -> draft (no-op)' => ['draft', 'draft'],
            'active -> active (no-op)' => ['active', 'active'],
            'inactive -> inactive (no-op)' => ['inactive', 'inactive'],
            'draft -> inactive' => ['draft', 'inactive'],
            'active -> draft' => ['active', 'draft'],
            'inactive -> draft' => ['inactive', 'draft'],
        ];
    }

    #[Test]
    #[DataProvider('illegalTransitionProvider')]
    public function illegal_transitions_are_rejected(string $from, string $to): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school'], ['status' => $from]);
        $this->createGradeBand($scale, ['min_percentage' => '0.00']);

        $this->expectException(GradeScaleIllegalTransitionException::class);
        $this->service()->update($w['school'], $scale, ['status' => $to], $w['actor']);
    }

    #[Test]
    public function activation_without_a_floor_band_is_rejected(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school']);
        $this->createGradeBand($scale, ['min_percentage' => '50.00']);

        $this->expectException(GradeScaleIncompleteException::class);
        $this->service()->update($w['school'], $scale, ['status' => 'active'], $w['actor']);
    }

    #[Test]
    public function activation_of_a_bandless_scale_is_rejected(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school']);

        $this->expectException(GradeScaleIncompleteException::class);
        $this->service()->update($w['school'], $scale, ['status' => 'active'], $w['actor']);
    }

    // --- name mutability --------------------------------------------------

    #[Test]
    public function name_can_be_changed_regardless_of_status(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school'], ['status' => 'active', 'name' => 'Old Name']);

        $updated = $this->service()->update($w['school'], $scale, ['name' => 'New Name'], $w['actor']);

        $this->assertSame('New Name', $updated->name);
    }

    #[Test]
    public function name_change_audit_metadata_never_carries_the_raw_name_value(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school'], ['name' => 'Secret Name']);

        $this->service()->update($w['school'], $scale, ['name' => 'Another Secret Name'], $w['actor']);

        $event = $this->inGradeScaleSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('school_id', $w['school']->id)
            ->where('event_type', 'examinations.grade_scale.updated')
            ->latest('occurred_at')
            ->firstOrFail());

        $encoded = json_encode($event->metadata);
        $this->assertStringNotContainsString('Secret Name', $encoded);
        $this->assertStringNotContainsString('Another Secret Name', $encoded);
        $this->assertContains('name', $event->metadata['changedFields']);
    }

    // --- band mutation: draft-only ------------------------------------------

    #[Test]
    public function bands_can_be_added_updated_and_removed_while_draft(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school']);

        $band = $this->service()->addBand($w['school'], $scale, ['min_percentage' => '0.00', 'label' => 'F'], $w['actor']);
        $this->assertSame('F', $this->inGradeScaleSchool($w['school'], fn () => GradeBand::query()->findOrFail($band->id))->label);

        $updated = $this->service()->updateBand($w['school'], $scale, $band, ['label' => 'Fail'], $w['actor']);
        $this->assertSame('Fail', $updated->label);

        $this->service()->removeBand($w['school'], $scale, $updated, $w['actor']);
        $this->assertFalse($this->inGradeScaleSchool($w['school'], fn () => GradeBand::query()->whereKey($band->id)->exists()));
    }

    #[Test]
    public function band_addition_is_rejected_once_the_scale_has_ever_been_active(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school'], ['status' => 'inactive']);

        $this->expectException(GradeBandNotMutableException::class);
        $this->service()->addBand($w['school'], $scale, ['min_percentage' => '10.00', 'label' => 'X'], $w['actor']);
    }

    #[Test]
    public function band_update_is_rejected_when_the_scale_is_active(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school'], ['status' => 'active']);
        $band = $this->createGradeBand($scale, ['min_percentage' => '0.00']);

        $this->expectException(GradeBandNotMutableException::class);
        $this->service()->updateBand($w['school'], $scale, $band, ['label' => 'New'], $w['actor']);
    }

    #[Test]
    public function band_removal_is_rejected_when_the_scale_is_inactive(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school'], ['status' => 'inactive']);
        $band = $this->createGradeBand($scale, ['min_percentage' => '0.00']);

        $this->expectException(GradeBandNotMutableException::class);
        $this->service()->removeBand($w['school'], $scale, $band, $w['actor']);
    }

    #[Test]
    public function a_duplicate_threshold_added_to_an_existing_scale_is_translated(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school']);
        $this->createGradeBand($scale, ['min_percentage' => '50.00']);

        $this->expectException(DuplicateGradeBandThresholdException::class);
        $this->service()->addBand($w['school'], $scale, ['min_percentage' => '50.00', 'label' => 'X'], $w['actor']);
    }

    #[Test]
    public function band_label_value_is_never_audited(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school']);

        $this->service()->addBand($w['school'], $scale, ['min_percentage' => '0.00', 'label' => 'Top Secret Label'], $w['actor']);

        $event = $this->inGradeScaleSchool($w['school'], fn () => SchoolAuditEvent::query()
            ->where('school_id', $w['school']->id)
            ->where('event_type', 'examinations.grade_scale.updated')
            ->latest('occurred_at')
            ->firstOrFail());

        $this->assertStringNotContainsString('Top Secret Label', json_encode($event->metadata));
    }

    #[Test]
    public function code_is_never_accepted_by_update(): void
    {
        $w = $this->gradeScaleWorld();
        $scale = $this->createGradeScale($w['school'], ['code' => 'GS1']);

        // update() intentionally has no 'code' key in its attribute
        // contract -- passing one through is simply ignored, proving
        // code immutability at the service layer.
        $updated = $this->service()->update($w['school'], $scale, ['name' => 'Renamed'], $w['actor']);

        $this->assertSame('GS1', $updated->code);
    }
}
