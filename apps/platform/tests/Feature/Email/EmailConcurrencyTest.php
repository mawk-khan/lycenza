<?php

namespace Tests\Feature\Email;

use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\EmailProviderReference;
use App\Models\School;
use App\Models\User;
use App\Support\Email\EmailState;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\UuidV7;
use Tests\Concerns\CreatesEmailFixtures;
use Tests\Concerns\ForcesConcurrentOverlap;
use Tests\TestCase;

/**
 * Phase 0O.9A (ADR 0055 sections 9.3 and 11.3): REAL concurrency -- two
 * separate OS processes against real PostgreSQL, with the overlap forced
 * and observed (the contender is seen blocked on the holder's lock before
 * the holder commits), never assumed and never slept for.
 *
 * Committed fixtures (no DatabaseTransactions): the child processes are
 * separate sessions and could never see uncommitted rows.
 */
class EmailConcurrencyTest extends TestCase
{
    use CreatesEmailFixtures, ForcesConcurrentOverlap;

    /** @var array<int, string> */
    protected $connectionsToTransact = [];

    private ?School $school = null;

    private ?User $admin = null;

    protected function tearDown(): void
    {
        DB::connection('pgsql_admin')->table('email_suppressions')->delete();
        DB::connection('pgsql_admin')->table('email_events')->delete();
        if ($this->school !== null) {
            $this->deleteSchoolAsAdmin($this->school); // cascades messages, attempts, references, invitations
        }
        // E21.4 (F1): only the migration role can delete a User (test cleanup).
        if ($this->admin !== null) {
            DB::connection('pgsql_admin')->table('users')->where('id', $this->admin->id)->delete();
        }

        parent::tearDown();
    }

    /** A committed, due, pending invitation email (queued while email was disabled). */
    private function pendingInvitationEmail(): EmailMessage
    {
        [$this->admin, $this->school] = $this->createSchoolAdmin('school_admin');
        config(['email.provider' => 'none']);
        [$email] = $this->queueInvitationEmail($this->school, $this->admin);
        config(['email.provider' => 'fake']);
        $this->makeDue($this->school, $email->id);

        return $email;
    }

    /** @return array{0: string, 1: string} */
    private function race(string $holderOperation, string $contenderOperation, string $messageId, ?string $holderArg = null, ?string $contenderArg = null): array
    {
        $script = base_path('tests/Support/race-email-message.php');

        return $this->raceWithHeldHolder(
            array_values(array_filter([PHP_BINARY, $script, $this->school->id, $messageId, $holderOperation, $holderArg])),
            array_values(array_filter([PHP_BINARY, $script, $this->school->id, $messageId, $contenderOperation, $contenderArg])),
        );
    }

    #[Test]
    public function two_workers_claiming_one_message_never_both_own_it(): void
    {
        $email = $this->pendingInvitationEmail();

        [$holder, $contender] = $this->race('claim', 'claim', $email->id);

        $this->assertSame(['claimed', 'skipped'], [$holder, $contender]);
        $this->assertSame(EmailState::Submitting, $this->emailRow($this->school, $email->id)->status);
    }

    #[Test]
    public function a_cancel_racing_a_claim_never_cancels_an_in_flight_message(): void
    {
        $email = $this->pendingInvitationEmail();

        [$holder, $contender] = $this->race('claim', 'cancel', $email->id);

        $this->assertSame(['claimed', 'not_cancelled'], [$holder, $contender]);
        $this->assertSame(EmailState::Submitting, $this->emailRow($this->school, $email->id)->status);
    }

    #[Test]
    public function a_claim_racing_a_cancel_never_submits_the_cancelled_message(): void
    {
        $email = $this->pendingInvitationEmail();

        [$holder, $contender] = $this->race('cancel', 'claim', $email->id);

        $this->assertSame(['cancelled', 'skipped'], [$holder, $contender]);
        $this->assertSame(EmailState::Cancelled, $this->emailRow($this->school, $email->id)->status);
    }

    #[Test]
    public function an_operator_retry_racing_a_worker_does_not_touch_the_in_flight_message(): void
    {
        $email = $this->pendingInvitationEmail();

        [$holder, $contender] = $this->race('claim', 'retry', $email->id);

        $this->assertSame(['claimed', 'not_retried'], [$holder, $contender]);
        $row = $this->emailRow($this->school, $email->id);
        $this->assertSame([EmailState::Submitting, 0], [$row->status, $row->manual_retries]);
    }

    #[Test]
    public function the_same_provider_event_delivered_twice_concurrently_is_stored_once(): void
    {
        $email = $this->pendingInvitationEmail();

        [$holder, $contender] = $this->race('ingest', 'ingest', $email->id, 'evt-dup', 'evt-dup');

        $this->assertSame(['stored', 'duplicate'], [$holder, $contender]);
        $this->assertSame(1, EmailEvent::query()->where('event_key', 'evt-dup')->count());
    }

    #[Test]
    public function a_bounce_and_a_complaint_applied_concurrently_serialize_on_the_message(): void
    {
        $email = $this->pendingInvitationEmail();
        $this->submitEmail($this->school, $email->id);
        $providerId = $this->emailRow($this->school, $email->id)->provider_message_id;
        $this->assertNotNull(EmailProviderReference::query()->find($providerId));

        $event = fn (string $key, string $type) => EmailEvent::query()->create([
            'id' => (string) new UuidV7, 'provider' => 'fake', 'event_key' => $key, 'type' => $type,
            'provider_message_id' => $providerId, 'received_at' => now(), 'result' => 'received',
        ])->id;
        $bounce = $event('evt-b', 'bounce_permanent');
        $complaint = $event('evt-c', 'complaint');

        [$holder, $contender] = $this->race('apply', 'apply', $email->id, $bounce, $complaint);

        $this->assertSame(['applied', 'applied'], [$holder, $contender]);
        $this->assertSame(EmailState::Bounced, $this->emailRow($this->school, $email->id)->status, 'the first applied event wins; the second never moves it');
        $this->assertSame(['applied', 'stale'], [EmailEvent::query()->find($bounce)->result, EmailEvent::query()->find($complaint)->result]);
        $this->assertSame(1, DB::table('email_suppressions')->where('scope', 'all')->whereNull('released_at')->count());
    }
}
