<?php

namespace Tests\Feature\Email;

use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\EmailProviderReference;
use App\Models\EmailSubmissionAttempt;
use App\Models\School;
use App\Support\Email\EmailPurpose;
use App\Support\Email\OutboundEmailGateway;
use App\Support\Email\PlatformEmailScope;
use App\Support\Email\Suppression\EmailSuppressionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesEmailFixtures;
use Tests\TestCase;

/**
 * E21.2A (E21-D2; E21.1 findings L1-L4): `platform:email-prune` against real
 * PostgreSQL, on a fixed clock.
 */
class EmailRetentionPruneTest extends TestCase
{
    use CreatesEmailFixtures;

    private const DAYS = 180;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutRetryJitter();
        $this->holdEmailSubmission();
        $this->fakeEmail();
        $this->now = Carbon::parse('2027-06-15 12:00:00');
        $this->travelTo($this->now);
        config(['email.retention_days' => self::DAYS, 'retention.hold_school_ids' => [], 'retention.batch_size' => 500]);
    }

    /** A School message, finished (cancelled) $ageSeconds before now, with one attempt and one provider reference. */
    private function finished(School $school, int $ageSeconds): EmailMessage
    {
        [$email] = $this->queueStandardEmail($school, $this->createUser(), 'p'.Str::random(6).'@school-os.test');

        return $this->inSchool($school, function () use ($email, $school, $ageSeconds) {
            EmailMessage::query()->whereKey($email->id)->update(['status' => 'cancelled', 'finished_at' => $this->now->copy()->subSeconds($ageSeconds)]);
            EmailSubmissionAttempt::query()->create([
                'id' => (string) Str::uuid7(), 'school_id' => $school->id, 'email_message_id' => $email->id, 'attempt_number' => 1,
                'started_at' => $this->now, 'completed_at' => $this->now, 'outcome' => 'permanent_failure', 'provider' => 'fake', 'duration_ms' => 1,
            ]);
            EmailProviderReference::query()->create(['provider' => 'fake', 'provider_message_id' => 'pm-'.$email->id, 'email_message_id' => $email->id, 'school_id' => $school->id]);

            return EmailMessage::query()->findOrFail($email->id);
        });
    }

    private function identityMessage(int $ageSeconds): string
    {
        $message = app(OutboundEmailGateway::class)->queueForIdentity(EmailPurpose::AccountRecovery, (string) Str::uuid7(), 'id'.Str::random(6).'@example.test', 'Reset', 'Body', null, $this->now->copy()->addHour());

        app(PlatformEmailScope::class)->run(function () use ($message, $ageSeconds) {
            EmailMessage::query()->whereKey($message->id)->update(['status' => 'cancelled', 'finished_at' => $this->now->copy()->subSeconds($ageSeconds)]);
            EmailProviderReference::query()->create(['provider' => 'fake', 'provider_message_id' => 'pm-'.$message->id, 'email_message_id' => $message->id, 'school_id' => null]);
        });

        return $message->id;
    }

    private function event(int $ageSeconds, string $result = 'applied'): EmailEvent
    {
        return EmailEvent::query()->create([
            'provider' => 'fake', 'event_key' => Str::random(20), 'type' => 'bounce_permanent', 'bounce_class' => 'mailbox_unknown',
            'received_at' => $this->now->copy()->subSeconds($ageSeconds), 'result' => $result, 'processed_at' => $result === 'received' ? null : $this->now,
        ]);
    }

    private function messageExists(?School $school, string $id): bool
    {
        return $school === null
            ? app(PlatformEmailScope::class)->run(fn () => EmailMessage::query()->whereKey($id)->exists())
            : $this->inSchool($school, fn () => EmailMessage::query()->whereKey($id)->exists());
    }

    private function days(int $days): int
    {
        return $days * 86400;
    }

    #[Test]
    public function nothing_is_deleted_while_retention_is_unconfigured(): void
    {
        config(['email.retention_days' => null]);
        $school = $this->createSchool();
        $old = $this->finished($school, $this->days(400));
        $event = $this->event($this->days(400));

        $this->artisan('platform:email-prune')->expectsOutputToContain('not configured')->assertSuccessful();

        $this->assertTrue($this->messageExists($school, $old->id));
        $this->assertTrue(EmailEvent::query()->whereKey($event->id)->exists());
    }

    #[Test]
    public function an_invalid_period_fails_and_deletes_nothing(): void
    {
        $school = $this->createSchool();
        $old = $this->finished($school, $this->days(400));

        foreach (['0', '-3', '1.5', 'abc'] as $invalid) {
            config(['email.retention_days' => $invalid]);
            $this->artisan('platform:email-prune')->assertFailed();
        }

        $this->assertTrue($this->messageExists($school, $old->id));
    }

    #[Test]
    public function finished_messages_past_the_period_go_with_their_attempts_and_provider_references(): void
    {
        $school = $this->createSchool();
        $old = $this->finished($school, $this->days(self::DAYS) + 1);
        $boundary = $this->finished($school, $this->days(self::DAYS));
        $young = $this->finished($school, $this->days(10));
        [$waiting] = $this->queueStandardEmail($school, $this->createUser(), 'waiting@school-os.test');

        $this->artisan('platform:email-prune')->assertSuccessful();

        $this->assertFalse($this->messageExists($school, $old->id));
        $this->assertSame(0, $this->inSchool($school, fn () => EmailSubmissionAttempt::query()->where('email_message_id', $old->id)->count()));
        // E21.1 L2: no orphaned provider reference.
        $this->assertFalse(EmailProviderReference::query()->where('email_message_id', $old->id)->exists());

        foreach ([$boundary, $young] as $kept) {
            $this->assertTrue($this->messageExists($school, $kept->id));
            $this->assertTrue(EmailProviderReference::query()->where('email_message_id', $kept->id)->exists());
        }
        // A message still waiting is never eligible, whatever its age.
        $this->assertTrue($this->messageExists($school, $waiting->id));
    }

    #[Test]
    public function identity_level_messages_are_pruned_too(): void
    {
        // E21.1 L1: account recovery / security notices have no School.
        $old = $this->identityMessage($this->days(self::DAYS + 1));
        $young = $this->identityMessage($this->days(5));

        $this->artisan('platform:email-prune')->assertSuccessful();

        $this->assertFalse($this->messageExists(null, $old));
        $this->assertFalse(EmailProviderReference::query()->where('email_message_id', $old)->exists());
        $this->assertTrue($this->messageExists(null, $young));
    }

    #[Test]
    public function an_event_a_suppression_still_references_is_kept_and_never_aborts_the_batch(): void
    {
        // E21.1 L3: deleting such an event would fire the FK's SET NULL into
        // the suppression guard. It is skipped; the others still go.
        config(['retention.batch_size' => 1]);
        $referenced = $this->event($this->days(400));
        $referencedReleased = $this->event($this->days(400));
        $free = [$this->event($this->days(400)), $this->event($this->days(300))];
        $unprocessed = $this->event($this->days(400), 'received');
        $young = $this->event($this->days(20));

        $suppressions = app(EmailSuppressionService::class);
        $suppressions->suppress('bounced@example.test', 'all', 'hard_bounce', $referenced->id);
        $suppressions->suppress('released@example.test', 'all', 'hard_bounce', $referencedReleased->id);
        $suppressions->release('released@example.test', null, 'operator_confirmed');

        $this->artisan('platform:email-prune')->assertSuccessful();

        foreach ($free as $event) {
            $this->assertFalse(EmailEvent::query()->whereKey($event->id)->exists());
        }
        foreach ([$referenced, $referencedReleased, $unprocessed, $young] as $event) {
            $this->assertTrue(EmailEvent::query()->whereKey($event->id)->exists());
        }
        $this->assertSame(2, DB::table('email_suppressions')->count(), 'suppressions are never pruned here');
    }

    #[Test]
    public function each_school_is_pruned_in_its_own_context_and_a_held_school_is_untouched(): void
    {
        $a = $this->createSchool();
        $b = $this->createSchool();
        $held = $this->createSchool();
        config(['retention.hold_school_ids' => [$held->id]]);
        $oldA = $this->finished($a, $this->days(200));
        $youngB = $this->finished($b, $this->days(2));
        $oldB = $this->finished($b, $this->days(250));
        $oldHeld = $this->finished($held, $this->days(500));

        $this->artisan('platform:email-prune')->expectsOutputToContain('1 School(s) held')->assertSuccessful();

        $this->assertFalse($this->messageExists($a, $oldA->id));
        $this->assertFalse($this->messageExists($b, $oldB->id));
        $this->assertTrue($this->messageExists($b, $youngB->id));
        $this->assertTrue($this->messageExists($held, $oldHeld->id));
        $this->assertTrue(EmailProviderReference::query()->where('email_message_id', $oldHeld->id)->exists());
    }

    #[Test]
    public function the_platform_hold_keeps_identity_level_messages_and_events(): void
    {
        // E21.2B: records that belong to no School are held as one group.
        config(['retention.hold_platform' => true]);
        $identity = $this->identityMessage($this->days(400));
        $event = $this->event($this->days(400));

        $this->artisan('platform:email-prune')->assertSuccessful();

        $this->assertTrue($this->messageExists(null, $identity));
        $this->assertTrue(EmailEvent::query()->whereKey($event->id)->exists());
    }

    #[Test]
    public function batches_dry_run_and_reruns_are_safe(): void
    {
        config(['retention.batch_size' => 2]);
        $school = $this->createSchool();
        $old = collect(range(1, 5))->map(fn () => $this->finished($school, $this->days(300)));

        $this->artisan('platform:email-prune', ['--dry-run' => true])->expectsOutputToContain('Dry run: would delete 5 School message(s)')->assertSuccessful();
        $old->each(fn ($m) => $this->assertTrue($this->messageExists($school, $m->id)));

        $this->artisan('platform:email-prune')->expectsOutputToContain('Deleted 5 School message(s)')->assertSuccessful();
        $this->artisan('platform:email-prune')->expectsOutputToContain('Deleted 0 School message(s)')->assertSuccessful();
        $old->each(fn ($m) => $this->assertFalse($this->messageExists($school, $m->id)));
    }
}
