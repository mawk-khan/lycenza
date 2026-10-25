<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\Audience\FeeReminder\FeeReminderSelection;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Identity\Application\AccountLinkService;
use App\Models\School;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFeesFixtures;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * ORG.9D-2 (ADR 0076 §74): two real OS processes publish the SAME approved
 * fee reminder. The holder's claim is uncommitted while the contender is
 * observed BLOCKED on it; once the holder commits, the contender's claim
 * matches nothing and it returns the published state as a no-op. Exactly one
 * message, one recipient, one email delivery and one set of reminder lines
 * -- never a duplicate reminder, never an integrity error.
 *
 * (Publish vs a payment, and publish vs a Guardian unlink, share no lock:
 * publish reads balances and links as of its own statements, so either the
 * change committed first and is reflected, or it lands after the immutable
 * snapshot -- both covered sequentially in FeeReminderTest.)
 */
class FeeReminderConcurrencyTest extends TestCase
{
    use CreatesFeesFixtures, CreatesFinanceFixtures, CreatesTenancyFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private School $school;

    /** @var list<string> */
    private array $userIds = [];

    protected function tearDown(): void
    {
        $this->purgeSchoolsAsAdmin([$this->school->id]);
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

    #[Test]
    public function two_processes_publishing_the_same_reminder_send_it_once(): void
    {
        Config::set('communications.channels.email.enabled', true);
        $this->school = $this->createSchool();
        $year = $this->createAcademicYear($this->school, ['status' => 'active', 'starts_on' => now()->subDays(100)->toDateString(), 'ends_on' => now()->addDays(100)->toDateString()]);
        $receivable = $this->createLedgerAccount($this->school, ['code' => 'AR-RACE', 'type' => 'asset']);
        $revenue = $this->createLedgerAccount($this->school, ['code' => 'INC-RACE', 'type' => 'income']);
        $student = $this->createStudent($this->school, ['status' => 'active']);
        $this->assessCharge($this->school, $student, $year, $receivable, $revenue, '250.00');

        $initiator = $this->admin();
        $approver = $this->admin();
        $parent = $this->createUser(['email' => 'race-parent-'.bin2hex(random_bytes(4)).'@school-os.test']);
        $this->userIds[] = $parent->id;
        $guardian = $this->createGuardian($this->school);
        $this->createStudentGuardianRelationship($student, $guardian, ['is_legal_guardian' => true]);
        app(AccountLinkService::class)->linkGuardian($this->school, $guardian, $this->createMembership($parent, $this->school), $initiator);

        $service = app(AnnouncementService::class);
        $draft = $service->createDraft($this->school, $initiator, 'Fees', 'Please pay.', CommunicationPriority::Normal, CommunicationAudienceType::FeeReminder, feeReminder: new FeeReminderSelection);
        $approvals = app(CommunicationApprovalService::class);
        $approvals->approve($approvals->submit($draft, $initiator), $approver);

        $command = ['php', __DIR__.'/../../Support/publish-announcement.php', $this->school->id, $draft->id, $initiator->id];
        [$holder, $contender] = $this->raceWithHeldHolder($command, $command);

        $messageId = app(TenantContext::class)->withSchool($this->school, fn () => DB::table('communication_announcements')->where('id', $draft->id)->value('message_id'));
        $this->assertNotNull($messageId);
        $this->assertSame("published:{$messageId}", $holder);
        $this->assertSame("published:{$messageId}", $contender, 'The loser returns the published state; it publishes nothing itself.');
        app(TenantContext::class)->withSchool($this->school, function () use ($draft, $messageId) {
            $this->assertSame(1, DB::table('communication_messages')->where('announcement_id', $draft->id)->count());
            $this->assertSame(1, DB::table('communication_recipients')->where('message_id', $messageId)->count());
            $this->assertSame(1, DB::table('communication_deliveries')->whereIn('recipient_id', DB::table('communication_recipients')->where('message_id', $messageId)->select('id'))->count());
            $this->assertSame(['250.00'], DB::table('communication_fee_reminder_lines')->pluck('outstanding_amount')->map(fn ($a) => (string) $a)->all());
        });
    }
}
