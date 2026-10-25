<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Infrastructure\CommunicationEvent;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\Feature\Communications\Concerns\CreatesCommunicationEventFixtures;
use Tests\TestCase;

/**
 * ORG.9D-3 (ADR 0076 §75): two real OS processes race one Event. Every
 * lifecycle step locks the Event row first, so the contender is observed
 * BLOCKED on the holder's uncommitted step and then decides on the
 * committed state: one coherent outcome, never unapproved terms published,
 * never a duplicate transition.
 */
class CommunicationEventConcurrencyTest extends TestCase
{
    use CreatesCommunicationEventFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    /** @var list<string> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        if ($this->school !== null) {
            $this->purgeSchoolsAsAdmin([$this->school->id]);
        }
        DB::connection('pgsql_admin')->table('users')->whereIn('id', $this->userIds)->delete();

        parent::tearDown();
    }

    private function admin(): User
    {
        $user = $this->createUser();
        $this->userIds[] = $user->id;
        $this->assignSchoolRole($this->createMembership($user, $this->school), 'school_admin');

        return $user;
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> */
    public static function races(): array
    {
        // [setup state, holder op, contender op, holder output, contender output, final status]
        return [
            'edit first, then publish: unapproved terms never publish' => ['approved', 'edit', 'publish', 'ok:draft', 'rejected:ApprovalRequiredException', 'draft'],
            'publish first, then edit: a published Event is not edited' => ['approved', 'publish', 'edit', 'ok:published', 'rejected:InvalidAnnouncementTransitionException', 'published'],
            'two publishes: one transition' => ['approved', 'publish', 'publish', 'ok:published', 'ok:published', 'published'],
            'publish then cancel: cancelled, publication kept' => ['approved', 'publish', 'cancel', 'ok:published', 'ok:cancelled', 'cancelled'],
            'two cancels: one transition' => ['published', 'cancel', 'cancel', 'ok:cancelled', 'ok:cancelled', 'cancelled'],
            'approve first, then withdraw: the decision stands' => ['pending', 'approve', 'withdraw', 'ok:approved', 'rejected:InvalidAnnouncementTransitionException', 'approved'],
        ];
    }

    #[Test]
    #[DataProvider('races')]
    public function one_coherent_outcome(string $state, string $holderOp, string $contenderOp, string $holderOut, string $contenderOut, string $final): void
    {
        $this->school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $preparer = $this->admin();
        $approver = $this->admin();
        $event = $this->draftEvent($this->school, $preparer, ['starts_on' => '2027-06-01', 'ends_on' => '2027-06-01']);
        if ($state === 'pending') {
            $this->events()->submit($event, $preparer);
        } else {
            $this->approveEvent($event, $preparer, $approver);
            if ($state === 'published') {
                $this->events()->publish($this->freshEvent($event), $preparer);
            }
        }

        $actorFor = fn (string $op) => $op === 'approve' ? $approver->id : $preparer->id;
        $script = fn (string $op) => ['php', __DIR__.'/../../Support/communication-event-op.php', $this->school->id, $event->id, $actorFor($op), $op];
        [$holder, $contender] = $this->raceWithHeldHolder($script($holderOp), $script($contenderOp));

        $this->assertSame([$holderOut, $contenderOut], [$holder, $contender]);
        $row = app(TenantContext::class)->withSchool($this->school, fn () => CommunicationEvent::query()->findOrFail($event->id));
        $this->assertSame($final, $row->status);
        if ($holderOp === 'edit') {
            $this->assertNull($row->published_at, 'The edited terms were never published.');
        }
        $audits = app(TenantContext::class)->withSchool($this->school, fn () => DB::table('school_audit_events')->where('subject_id', $event->id)
            ->whereIn('event_type', ['communication_event.published', 'communication_event.cancelled'])->pluck('event_type')->countBy()->all());
        foreach ($audits as $type => $count) {
            $this->assertSame(1, $count, "{$type} recorded once.");
        }
    }
}
