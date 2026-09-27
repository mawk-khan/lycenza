<?php

namespace Tests\Feature\Email;

use App\Models\EmailMessage;
use App\Support\Email\EmailState;
use App\Support\Email\EmailSubmissionService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesEmailFixtures;
use Tests\TestCase;

/**
 * Phase 0O.9A (ADR 0055 section 15): fairness at claim time -- per-School
 * and global in-flight caps with slots only critical mail may use, and
 * separate rate buckets. Over budget is DEFERRED, never failed.
 */
class EmailThroughputTest extends TestCase
{
    use CreatesEmailFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->holdEmailSubmission();
        $this->fakeEmail();
        $this->freezeSecond();
    }

    /** Claims (leases) a message without submitting it: an in-flight slot. */
    private function claim($school, string $id): ?EmailMessage
    {
        return $this->inSchool($school, fn () => app(EmailSubmissionService::class)->claim($id));
    }

    #[Test]
    public function standard_mail_leaves_the_reserved_in_flight_slot_to_critical_mail(): void
    {
        config(['email.budgets.school_max_in_flight' => 3, 'email.budgets.critical_reserved_in_flight' => 1]);
        [$admin, $school] = $this->createSchoolAdmin('school_admin');

        $standard = array_map(fn (int $i) => $this->queueStandardEmail($school, $admin, "s{$i}@school-os.test")[0], range(1, 3));

        $this->assertNotNull($this->claim($school, $standard[0]->id));
        $this->assertNotNull($this->claim($school, $standard[1]->id));
        $this->assertNull($this->claim($school, $standard[2]->id), 'standard mail may use 3 - 1 slots');
        $deferred = $this->emailRow($school, $standard[2]->id);
        $this->assertSame([EmailState::Pending, 'in_flight_limit'], [$deferred->status, $deferred->status_code]);
        $this->assertTrue($deferred->next_attempt_at->isFuture());

        [$critical] = $this->queueInvitationEmail($school, $admin);
        $this->assertNotNull($this->claim($school, $critical->id), 'the reserved slot is there for an invitation');
    }

    #[Test]
    public function one_school_cannot_take_every_global_slot(): void
    {
        config(['email.budgets.school_max_in_flight' => 2, 'email.budgets.global_max_in_flight' => 3, 'email.budgets.critical_reserved_in_flight' => 0]);
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');

        $a = array_map(fn (int $i) => $this->queueStandardEmail($schoolA, $adminA, "a{$i}@school-os.test")[0], range(1, 3));
        $b = $this->queueStandardEmail($schoolB, $adminB, 'b@school-os.test')[0];

        $this->assertNotNull($this->claim($schoolA, $a[0]->id));
        $this->assertNotNull($this->claim($schoolA, $a[1]->id));
        $this->assertNull($this->claim($schoolA, $a[2]->id), 'School A is at its own cap');
        $this->assertNotNull($this->claim($schoolB, $b->id), 'School B still gets a slot');
    }

    #[Test]
    public function an_expired_lease_frees_its_slot(): void
    {
        config(['email.budgets.school_max_in_flight' => 2, 'email.budgets.critical_reserved_in_flight' => 1]);
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        [$first] = $this->queueStandardEmail($school, $admin, 'x@school-os.test');
        [$second] = $this->queueStandardEmail($school, $admin, 'y@school-os.test');

        $this->claim($school, $first->id);
        $this->assertNull($this->claim($school, $second->id));

        $this->travel(121)->seconds();
        $this->assertNotNull($this->claim($school, $second->id));
    }

    #[Test]
    public function rate_budgets_defer_and_critical_mail_has_its_own_bucket(): void
    {
        config(['email.budgets.school_standard_per_minute' => 2, 'email.budgets.school_critical_per_minute' => 5]);
        [$admin, $school] = $this->createSchoolAdmin('school_admin');

        $messages = array_map(fn (int $i) => $this->queueStandardEmail($school, $admin, "r{$i}@school-os.test")[0], range(1, 3));
        foreach ($messages as $message) {
            $this->submitEmail($school, $message->id);
        }

        $third = $this->emailRow($school, $messages[2]->id);
        $this->assertSame([EmailState::Pending, 'rate_budget', 0], [$third->status, $third->status_code, $third->attempts]);
        $this->assertTrue($third->next_attempt_at->isFuture());

        [$invitation] = $this->queueInvitationEmail($school, $admin);
        $this->assertSame(EmailState::Submitted, $this->submitEmail($school, $invitation->id)->status, 'announcements cannot exhaust the invitation budget');

        $this->travel(61)->seconds();
        $this->assertSame(EmailState::Submitted, $this->submitEmail($school, $messages[2]->id)->status);
    }

    #[Test]
    public function a_per_school_daily_budget_is_per_school(): void
    {
        config(['email.budgets.school_standard_per_day' => 1]);
        [$adminA, $schoolA] = $this->createSchoolAdmin('school_admin');
        [$adminB, $schoolB] = $this->createSchoolAdmin('school_admin');

        [$a1] = $this->queueStandardEmail($schoolA, $adminA, 'a1@school-os.test');
        [$a2] = $this->queueStandardEmail($schoolA, $adminA, 'a2@school-os.test');
        [$b1] = $this->queueStandardEmail($schoolB, $adminB, 'b1@school-os.test');

        $this->assertSame(EmailState::Submitted, $this->submitEmail($schoolA, $a1->id)->status);
        $this->assertSame('rate_budget', $this->submitEmail($schoolA, $a2->id)->status_code);
        $this->assertSame(EmailState::Submitted, $this->submitEmail($schoolB, $b1->id)->status);
        $this->assertSame(0, (int) DB::table('email_submission_attempts')->count(), 'attempt rows are RLS-scoped: none visible without a School');
    }
}
