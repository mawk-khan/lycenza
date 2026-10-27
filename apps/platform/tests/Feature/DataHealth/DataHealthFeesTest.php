<?php

namespace Tests\Feature\DataHealth;

use App\Domain\Fees\Application\FeeAssessmentService;
use App\Domain\Fees\Infrastructure\FeeAssessment;
use App\Domain\Students\Application\StudentEnrollmentService;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\DataHealth\DataHealthService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Fees\Concerns\CreatesFeeAssessmentFixtures;
use Tests\TestCase;

/**
 * SIS 8.1 (SB-03a): a live, uncancelled fee Charge for a billing period that
 * starts after its billed placement ended, with no other placement of the
 * Student covering it. A transfer is never a finding (the new placement
 * covers the period); an earlier period, a voided assessment and another
 * School are never findings. No amount is shown: money stays on the Charge.
 */
class DataHealthFeesTest extends TestCase
{
    use CreatesFeeAssessmentFixtures;

    private function proof(User $user): FreshMfaProof
    {
        return new FreshMfaProof($user->id, CarbonImmutable::now());
    }

    /** @return list<array<string, mixed>> */
    private function findings(array $w, ?User $viewer = null): array
    {
        $viewer ??= $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'enrollments.view', 'students.view']);

        return app(DataHealthService::class)->findings($w['school'], 'fees.charge_after_placement_ended', $viewer, 1)['findings']->items();
    }

    private function assessment(array $w, string $studentId, string $period): FeeAssessment
    {
        return $this->inSchool($w['school'], fn () => FeeAssessment::query()->where('student_id', $studentId)->where('billing_period_key', $period)->sole());
    }

    #[Test]
    public function a_charge_billed_for_a_period_after_the_placement_ended_is_flagged_and_a_transfer_is_not(): void
    {
        $w = $this->assessmentWorld();
        $admin = $this->createUserWithCapabilities($w['school'], ['students.view', 'students.manage', 'enrollments.view', 'enrollments.manage']);
        $leaver = $this->enroll($w);
        $mover = $this->enroll($w);
        $voided = $this->enroll($w);
        $stayer = $this->enroll($w);
        $this->executedRun($w, 'T1');
        $this->executedRun($w, 'T2'); // Term 2 starts 2026-11-01, billed in advance.

        $enrollments = app(StudentEnrollmentService::class);
        foreach ([$leaver, $voided] as $placement) {
            $enrollments->withdraw($placement, '2026-10-15', 'guardian_request', $admin, $this->proof($admin));
        }
        $enrollments->transferPlacement($mover, $this->sectionIn($w, $w['campus']), '7', '2026-10-15', $admin);
        app(FeeAssessmentService::class)->void($w['school'], $this->assessment($w, $voided->student_id, 'T2')->id, $w['actor'], 'wrong_placement', null, $this->proof($w['actor']));

        $flagged = $this->assessment($w, $leaver->student_id, 'T2');
        $findings = $this->findings($w, $this->createUserWithCapabilities($w['school'], ['finance.charges.view', 'finance.charges.manage', 'enrollments.view', 'students.view']));

        $this->assertSame([$flagged->charge_id], array_column($findings, 'entityId'), 'T1 started before the end; a transfer is covered; a voided assessment cancelled its Charge');
        $this->assertMatchesRegularExpression('/(Term 2|billing period) from 2026-11-01/', $findings[0]['detail']);
        $this->assertSame(1, substr_count($findings[0]['detail'], 'Term 2'), 'the period is named once');
        $this->assertStringContainsString('placement ended 2026-10-15 (withdrawn)', $findings[0]['detail']);
        $this->assertDoesNotMatchRegularExpression('/\d+\.\d{2}|7,?000/', $findings[0]['detail'], 'no amount: Finance owns the money');
        $this->assertSame(
            ["/app/finance/charges/{$flagged->charge_id}", "/app/finance/fee-runs/{$flagged->fee_assessment_run_id}", "/app/students/{$leaver->student_id}#enrollment-history"],
            array_column($findings[0]['links'], 'url'),
            'the void link for a finance.charges.manage holder',
        );
        $this->assertSame(
            ["/app/finance/charges/{$flagged->charge_id}", "/app/students/{$leaver->student_id}#enrollment-history"],
            array_column($this->findings($w)[0]['links'], 'url'),
            'no void link without finance.charges.manage',
        );
        $this->assertNotSame([], $this->inSchool($w['school'], fn () => FeeAssessment::query()->where('student_id', $stayer->student_id)->pluck('id')->all()));

        // Another School's equivalent leaver never reaches this School's result.
        $other = $this->assessmentWorld();
        $otherAdmin = $this->createUserWithCapabilities($other['school'], ['students.view', 'students.manage', 'enrollments.view', 'enrollments.manage']);
        $foreign = $this->enroll($other);
        $this->executedRun($other, 'T2');
        $enrollments->withdraw($foreign, '2026-10-15', 'guardian_request', $otherAdmin, $this->proof($otherAdmin));
        $this->assertCount(1, $this->findings($other));
        $this->assertSame([$flagged->charge_id], array_column($this->findings($w), 'entityId'));
    }
}
