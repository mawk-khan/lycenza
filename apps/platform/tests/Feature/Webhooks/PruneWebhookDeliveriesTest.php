<?php

namespace Tests\Feature\Webhooks;

use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Models\WebhookEndpoint;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0C closeout: `platform:webhook-deliveries-prune`. The clock is
 * frozen and every timestamp is relative to it, so nothing here depends
 * on a calendar date.
 */
class PruneWebhookDeliveriesTest extends TestCase
{
    use CreatesTenancyFixtures;

    private const RETENTION_DAYS = 30;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        // E21-D4: delivered and failed/abandoned rows have separate periods;
        // the shared cases below give both the same one.
        config(['webhooks.delivery_retention_days' => self::RETENTION_DAYS, 'webhooks.failed_delivery_retention_days' => self::RETENTION_DAYS, 'webhooks.prune_batch_size' => 500, 'retention.hold_school_ids' => []]);
    }

    private function endpoint(School $school): WebhookEndpoint
    {
        return app(TenantContext::class)->withSchool($school, fn () => WebhookEndpoint::query()->create([
            'school_id' => $school->id,
            'name' => 'prune-test',
            'url' => 'https://8.8.8.8/hook',
            'secret_encrypted' => 'secret',
            'status' => 'active',
            'created_by_user_id' => $this->createUser()->id,
        ]));
    }

    /**
     * A delivery in $status whose last state change was $ageSeconds ago,
     * with $attempts append-only attempt rows.
     */
    private function delivery(WebhookEndpoint $endpoint, string $status, int $ageSeconds, int $attempts = 2, ?Carbon $leaseExpiresAt = null): WebhookDelivery
    {
        $school = School::query()->findOrFail($endpoint->school_id);

        return app(TenantContext::class)->withSchool($school, function () use ($endpoint, $status, $ageSeconds, $attempts, $leaseExpiresAt) {
            $changedAt = Carbon::now()->subSeconds($ageSeconds);

            $delivery = WebhookDelivery::query()->create([
                'school_id' => $endpoint->school_id,
                'webhook_endpoint_id' => $endpoint->id,
                'event_id' => (string) Str::uuid(),
                'event_type' => 'school.setting.changed.v1',
                'status' => $status,
                'attempts' => $attempts,
                'processing_lease_expires_at' => $leaseExpiresAt,
                'delivered_at' => $status === 'delivered' ? $changedAt : null,
            ]);

            for ($n = 1; $n <= $attempts; $n++) {
                WebhookDeliveryAttempt::query()->create([
                    'school_id' => $endpoint->school_id,
                    'webhook_delivery_id' => $delivery->id,
                    'attempt_number' => $n,
                    'started_at' => $changedAt,
                    'completed_at' => $changedAt,
                    'response_status' => 500,
                    'outcome' => 'transient_failure',
                ]);
            }

            WebhookDelivery::query()->whereKey($delivery->id)->update(['created_at' => $changedAt, 'updated_at' => $changedAt]);

            return $delivery;
        });
    }

    private function exists(WebhookDelivery $delivery): bool
    {
        $school = School::query()->findOrFail($delivery->school_id);

        return app(TenantContext::class)->withSchool($school, fn () => WebhookDelivery::query()->whereKey($delivery->id)->exists());
    }

    private function attemptCount(WebhookDelivery $delivery): int
    {
        $school = School::query()->findOrFail($delivery->school_id);

        return app(TenantContext::class)->withSchool($school, fn () => WebhookDeliveryAttempt::query()->where('webhook_delivery_id', $delivery->id)->count());
    }

    private function days(int $days): int
    {
        return $days * 86400;
    }

    #[Test]
    public function it_deletes_nothing_while_retention_is_unconfigured(): void
    {
        config(['webhooks.delivery_retention_days' => null, 'webhooks.failed_delivery_retention_days' => null]);
        $old = $this->delivery($this->endpoint($this->createSchool()), 'delivered', $this->days(400));

        $this->artisan('platform:webhook-deliveries-prune')
            ->expectsOutputToContain('not configured')
            ->assertSuccessful();

        $this->assertTrue($this->exists($old));
        $this->assertSame(2, $this->attemptCount($old));
    }

    #[Test]
    public function it_prunes_only_old_terminal_deliveries_and_their_attempts(): void
    {
        $endpoint = $this->endpoint($this->createSchool());
        $old = $this->days(self::RETENTION_DAYS + 5);

        $oldDelivered = $this->delivery($endpoint, 'delivered', $old);
        $oldFailed = $this->delivery($endpoint, 'failed', $old);
        $oldAbandoned = $this->delivery($endpoint, 'abandoned', $old);

        $recentDelivered = $this->delivery($endpoint, 'delivered', $this->days(2));
        $oldPending = $this->delivery($endpoint, 'pending', $old, attempts: 0);
        $oldRetrying = $this->delivery($endpoint, 'retrying', $old);
        $oldDelivering = $this->delivery($endpoint, 'delivering', $old, leaseExpiresAt: Carbon::now()->addMinute());
        // Terminal status but a still-held processing lease: never touched.
        $leasedTerminal = $this->delivery($endpoint, 'failed', $old, leaseExpiresAt: Carbon::now()->addMinute());

        $this->artisan('platform:webhook-deliveries-prune')
            ->expectsOutputToContain('Pruned 3 terminal webhook')
            ->assertSuccessful();

        foreach ([$oldDelivered, $oldFailed, $oldAbandoned] as $pruned) {
            $this->assertFalse($this->exists($pruned));
            // Append-only attempts leave only with their delivery (cascade).
            $this->assertSame(0, $this->attemptCount($pruned));
        }

        foreach ([$recentDelivered, $oldPending, $oldRetrying, $oldDelivering, $leasedTerminal] as $kept) {
            $this->assertTrue($this->exists($kept), $kept->status);
        }
        $this->assertSame(2, $this->attemptCount($recentDelivered));
        $this->assertSame(2, $this->attemptCount($oldRetrying));
    }

    #[Test]
    public function the_retention_boundary_is_strict(): void
    {
        $endpoint = $this->endpoint($this->createSchool());
        $exactlyAtCutoff = $this->delivery($endpoint, 'delivered', $this->days(self::RETENTION_DAYS));
        $oneSecondOlder = $this->delivery($endpoint, 'delivered', $this->days(self::RETENTION_DAYS) + 1);

        $this->artisan('platform:webhook-deliveries-prune')->assertSuccessful();

        $this->assertTrue($this->exists($exactlyAtCutoff));
        $this->assertFalse($this->exists($oneSecondOlder));
    }

    #[Test]
    public function each_school_is_pruned_only_within_its_own_tenant_context(): void
    {
        $schoolA = $this->createSchool();
        $schoolB = $this->createSchool();
        $schoolC = $this->createSchool();
        $old = $this->days(self::RETENTION_DAYS + 1);

        $aOld = $this->delivery($this->endpoint($schoolA), 'delivered', $old);
        $bOld = $this->delivery($this->endpoint($schoolB), 'failed', $old);
        $cRecent = $this->delivery($this->endpoint($schoolC), 'delivered', $this->days(1));

        $this->artisan('platform:webhook-deliveries-prune')->assertSuccessful();

        $this->assertFalse($this->exists($aOld));
        $this->assertFalse($this->exists($bOld));
        $this->assertTrue($this->exists($cRecent));

        // Without a tenant context the runtime (RLS) connection sees no
        // webhook rows at all -- the command only ever works per School.
        app(TenantContext::class)->clearAll();
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    #[Test]
    public function it_works_in_bounded_batches_until_done(): void
    {
        config(['webhooks.prune_batch_size' => 2]);
        $endpoint = $this->endpoint($this->createSchool());
        $deliveries = [];
        for ($i = 0; $i < 5; $i++) {
            $deliveries[] = $this->delivery($endpoint, 'delivered', $this->days(self::RETENTION_DAYS + 3), attempts: 1);
        }

        $this->artisan('platform:webhook-deliveries-prune')
            ->expectsOutputToContain('Pruned 5 terminal webhook')
            ->assertSuccessful();

        foreach ($deliveries as $delivery) {
            $this->assertFalse($this->exists($delivery));
        }
    }

    #[Test]
    public function repeated_runs_are_safe_and_idempotent(): void
    {
        $endpoint = $this->endpoint($this->createSchool());
        $this->delivery($endpoint, 'abandoned', $this->days(self::RETENTION_DAYS + 9));
        $kept = $this->delivery($endpoint, 'pending', $this->days(self::RETENTION_DAYS + 9), attempts: 0);

        $this->artisan('platform:webhook-deliveries-prune')->expectsOutputToContain('Pruned 1 ')->assertSuccessful();
        $this->artisan('platform:webhook-deliveries-prune')->expectsOutputToContain('Pruned 0 ')->assertSuccessful();

        $this->assertTrue($this->exists($kept));
    }

    #[Test]
    public function dry_run_counts_without_deleting(): void
    {
        $endpoint = $this->endpoint($this->createSchool());
        $old = $this->delivery($endpoint, 'delivered', $this->days(self::RETENTION_DAYS + 2));
        $this->delivery($endpoint, 'delivered', $this->days(1));

        $this->artisan('platform:webhook-deliveries-prune', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run: 1 terminal webhook')
            ->assertSuccessful();

        $this->assertTrue($this->exists($old));
        $this->assertSame(2, $this->attemptCount($old));
    }

    #[Test]
    public function the_days_option_overrides_configuration_and_is_validated(): void
    {
        config(['webhooks.delivery_retention_days' => null, 'webhooks.failed_delivery_retention_days' => null]);
        $endpoint = $this->endpoint($this->createSchool());
        $tenDaysOld = $this->delivery($endpoint, 'delivered', $this->days(10));

        foreach (['0', '-3', 'abc', '1.5'] as $invalid) {
            $this->artisan('platform:webhook-deliveries-prune', ['--days' => $invalid])->assertFailed();
        }
        $this->assertTrue($this->exists($tenDaysOld));

        $this->artisan('platform:webhook-deliveries-prune', ['--days' => '7'])->assertSuccessful();
        $this->assertFalse($this->exists($tenDaysOld));
    }

    #[Test]
    public function delivered_and_failed_rows_follow_their_own_periods(): void
    {
        // E21-D4 adopted values: delivered 30 days, failed/abandoned 90 days.
        config(['webhooks.delivery_retention_days' => 30, 'webhooks.failed_delivery_retention_days' => 90]);
        $endpoint = $this->endpoint($this->createSchool());

        $delivered31 = $this->delivery($endpoint, 'delivered', $this->days(31));
        $failed31 = $this->delivery($endpoint, 'failed', $this->days(31));
        $abandoned89 = $this->delivery($endpoint, 'abandoned', $this->days(89));
        $failed90 = $this->delivery($endpoint, 'failed', $this->days(90));
        $failed91 = $this->delivery($endpoint, 'failed', $this->days(91));
        $abandoned91 = $this->delivery($endpoint, 'abandoned', $this->days(91));

        $this->artisan('platform:webhook-deliveries-prune')->expectsOutputToContain('Pruned 3 terminal webhook')->assertSuccessful();

        foreach ([$delivered31, $failed91, $abandoned91] as $pruned) {
            $this->assertFalse($this->exists($pruned), $pruned->status);
        }
        foreach ([$failed31, $abandoned89, $failed90] as $kept) {
            $this->assertTrue($this->exists($kept), $kept->status);
        }
    }

    #[Test]
    public function an_unset_failed_period_never_prunes_failed_rows(): void
    {
        config(['webhooks.failed_delivery_retention_days' => null]);
        $endpoint = $this->endpoint($this->createSchool());
        $delivered = $this->delivery($endpoint, 'delivered', $this->days(400));
        $failed = $this->delivery($endpoint, 'failed', $this->days(400));
        $abandoned = $this->delivery($endpoint, 'abandoned', $this->days(400));

        $this->artisan('platform:webhook-deliveries-prune')->assertSuccessful();

        $this->assertFalse($this->exists($delivered));
        $this->assertTrue($this->exists($failed));
        $this->assertTrue($this->exists($abandoned));

        // --failed-days applies to an explicit manual run only.
        $this->artisan('platform:webhook-deliveries-prune', ['--failed-days' => '0'])->assertFailed();
        $this->artisan('platform:webhook-deliveries-prune', ['--failed-days' => '365'])->assertSuccessful();
        $this->assertFalse($this->exists($failed));
        $this->assertFalse($this->exists($abandoned));
    }

    #[Test]
    public function a_held_school_is_never_pruned(): void
    {
        $held = $this->createSchool();
        $other = $this->createSchool();
        config(['retention.hold_school_ids' => [$held->id]]);
        $heldOld = $this->delivery($this->endpoint($held), 'delivered', $this->days(400));
        $otherOld = $this->delivery($this->endpoint($other), 'delivered', $this->days(400));

        $this->artisan('platform:webhook-deliveries-prune')->expectsOutputToContain('1 School(s) held')->assertSuccessful();

        $this->assertTrue($this->exists($heldOld));
        $this->assertFalse($this->exists($otherOld));
    }

    #[Test]
    public function audit_events_and_the_domain_event_outbox_are_never_pruned(): void
    {
        $school = $this->createSchool();
        $endpoint = $this->endpoint($school);
        $delivery = $this->delivery($endpoint, 'delivered', $this->days(self::RETENTION_DAYS + 30));

        app(TenantContext::class)->withSchool($school, fn () => app(AuditRecorder::class)->school($school, 'integrations.webhook_delivery.redelivery_requested', subject: $delivery));
        $outboxId = (string) Str::uuid();
        DB::table('domain_event_outbox')->insert([
            'id' => $outboxId,
            'school_id' => $school->id,
            'event_type' => 'school.setting.changed.v1',
            'event_version' => 1,
            'correlation_id' => (string) Str::uuid(),
            'payload' => json_encode(['key' => 'x']),
            'status' => 'dispatched',
            'occurred_at' => Carbon::now()->subDays(self::RETENTION_DAYS + 30),
            'available_at' => Carbon::now()->subDays(self::RETENTION_DAYS + 30),
            'created_at' => Carbon::now()->subDays(self::RETENTION_DAYS + 30),
            'updated_at' => Carbon::now()->subDays(self::RETENTION_DAYS + 30),
        ]);
        $auditCount = app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->count());

        $this->artisan('platform:webhook-deliveries-prune')->assertSuccessful();

        $this->assertFalse($this->exists($delivery));
        $this->assertSame($auditCount, app(TenantContext::class)->withSchool($school, fn () => SchoolAuditEvent::query()->count()));
        $this->assertTrue(DB::table('domain_event_outbox')->where('id', $outboxId)->exists());
    }

    #[Test]
    public function both_retention_prunes_are_scheduled_daily_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach (['platform:webhook-deliveries-prune', 'platform:idempotency-prune'] as $command) {
            /** @var Event|null $event */
            $event = $events->first(fn (Event $event) => str_contains((string) $event->command, $command));

            $this->assertNotNull($event, "{$command} is not scheduled");
            $this->assertTrue($event->withoutOverlapping, "{$command} must not overlap");
            $this->assertMatchesRegularExpression('/^\d+ \d+ \* \* \*$/', $event->expression, "{$command} must run daily");
        }
    }
}
