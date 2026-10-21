<?php

namespace Tests\Unit\Approval;

use App\Models\User;
use App\Support\Approval\ApprovalParticipants;
use App\Support\Approval\MakerCheckerPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ORG.8A (ADR 0076 §58): the shared maker/checker rule excludes EVERY
 * material participant the domain supplies, not only a requester, per item.
 */
class MakerCheckerPolicyTest extends TestCase
{
    private function user(string $id): User
    {
        return (new User)->forceFill(['id' => $id]);
    }

    #[Test]
    public function every_material_participant_is_disqualified_and_nobody_else(): void
    {
        $participants = new ApprovalParticipants('payroll_runs', 'run-1', ['preparer' => ['u-1'], 'amender' => ['u-2', 'u-3']]);
        $policy = new MakerCheckerPolicy;

        $this->assertSame('preparer', $policy->disqualification($this->user('u-1'), $participants));
        $this->assertSame('amender', $policy->disqualification($this->user('u-3'), $participants));
        $this->assertFalse($policy->permits($this->user('u-2'), $participants));
        $this->assertTrue($policy->permits($this->user('u-4'), $participants));
    }

    #[Test]
    public function participation_is_per_item(): void
    {
        $policy = new MakerCheckerPolicy;
        $mine = new ApprovalParticipants('fee_concessions', 'c-1', ['requester' => ['u-1']]);
        $theirs = new ApprovalParticipants('fee_concessions', 'c-2', ['requester' => ['u-2']]);

        $this->assertFalse($policy->permits($this->user('u-1'), $mine));
        $this->assertTrue($policy->permits($this->user('u-1'), $theirs));
    }

    #[Test]
    public function an_item_always_names_its_participants(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ApprovalParticipants('fee_concessions', 'c-1', []);
    }
}
