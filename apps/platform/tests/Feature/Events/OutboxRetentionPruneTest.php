<?php

namespace Tests\Feature\Events;

use App\Models\School;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\Retention\RetentionHolds;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * E21.2A (E21-D4): `platform:outbox-prune` against real PostgreSQL, on a
 * fixed clock. Processed rows go after the period, with their receipts. A
 * row a non-delivered webhook delivery still needs is kept.
 */
class OutboxRetentionPruneTest extends TestCase
{
    use CommitsRetentionFixtures, CreatesTenancyFixtures;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2027-06-15 12:00:00');
        $this->travelTo($this->now);
        config(['retention.outbox_days' => 30, 'retention.hold_school_ids' => [], 'retention.batch_size' => 500]);
    }

    /** An outbox row; `$processedDaysAgo` null = not acknowledged. */
    private function outbox(?School $school, string $status, ?int $processedDaysAgo, int $processedExtraSeconds = 0): string
    {
        $id = (string) Str::uuid7();
        $at = $this->now->copy()->subDays(400);
        DB::table('domain_event_outbox')->insert([
            'id' => $id, 'school_id' => $school?->id, 'event_type' => 'school.setting.changed.v1', 'event_version' => 1,
            'correlation_id' => (string) Str::uuid(), 'payload' => json_encode(['key' => 'x']), 'status' => $status,
            'occurred_at' => $at, 'available_at' => $at, 'dispatched_at' => $status === 'pending' ? null : $at,
            'processed_at' => $processedDaysAgo === null ? null : $this->now->copy()->subDays($processedDaysAgo)->subSeconds($processedExtraSeconds),
            'created_at' => $at, 'updated_at' => $at,
        ]);
        DB::table('event_consumer_receipts')->insert([
            'id' => (string) Str::uuid7(), 'consumer_name' => 'test-consumer', 'event_id' => $id, 'school_id' => $school?->id,
            'processed_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);

        return $id;
    }

    private function deliveryFor(School $school, string $eventId, string $status): void
    {
        app(TenantContext::class)->withSchool($school, function () use ($school, $eventId, $status) {
            $endpoint = WebhookEndpoint::query()->create([
                'school_id' => $school->id, 'name' => 'outbox-prune', 'url' => 'https://8.8.8.8/hook',
                'secret_encrypted' => 'secret', 'status' => 'active', 'created_by_user_id' => $this->createUser()->id,
            ]);
            WebhookDelivery::query()->create([
                'school_id' => $school->id, 'webhook_endpoint_id' => $endpoint->id, 'event_id' => $eventId,
                'event_type' => 'school.setting.changed.v1', 'status' => $status, 'attempts' => 1,
                'delivered_at' => $status === 'delivered' ? $this->now : null,
            ]);
        });
    }

    private function exists(string $id): bool
    {
        return DB::table('domain_event_outbox')->where('id', $id)->exists();
    }

    private function receipts(string $id): int
    {
        return DB::table('event_consumer_receipts')->where('event_id', $id)->count();
    }

    #[Test]
    public function nothing_is_deleted_while_retention_is_unconfigured(): void
    {
        config(['retention.outbox_days' => null]);
        $old = $this->outbox($this->createSchool(), 'dispatched', 400);

        $this->artisan('platform:outbox-prune')->expectsOutputToContain('not configured')->assertSuccessful();
        $this->assertTrue($this->exists($old));

        config(['retention.outbox_days' => '0']);
        $this->artisan('platform:outbox-prune')->assertFailed();
        $this->assertTrue($this->exists($old));
    }

    #[Test]
    public function only_processed_rows_past_the_period_go_and_their_receipts_with_them(): void
    {
        $school = $this->createSchool();
        $old = $this->outbox($school, 'dispatched', 30, 1);
        $boundary = $this->outbox($school, 'dispatched', 30);
        $young = $this->outbox($school, 'dispatched', 5);
        $unacknowledged = $this->outbox($school, 'dispatched', null);
        $pending = $this->outbox($school, 'pending', null);
        $failed = $this->outbox($school, 'failed', null);
        $platform = $this->outbox(null, 'dispatched', 90);

        $this->artisan('platform:outbox-prune')->expectsOutputToContain('Deleted 1 School and 1 platform outbox row(s)')->assertSuccessful();

        foreach ([$old, $platform] as $gone) {
            $this->assertFalse($this->exists($gone));
            $this->assertSame(0, $this->receipts($gone));
        }
        foreach ([$boundary, $young, $unacknowledged, $pending, $failed] as $kept) {
            $this->assertTrue($this->exists($kept));
            $this->assertSame(1, $this->receipts($kept));
        }
    }

    #[Test]
    public function a_row_a_non_delivered_webhook_delivery_still_needs_is_kept(): void
    {
        $school = $this->createSchool();
        $retrying = $this->outbox($school, 'dispatched', 60);
        $failedDelivery = $this->outbox($school, 'dispatched', 60);
        $deliveredOnly = $this->outbox($school, 'dispatched', 60);
        $this->deliveryFor($school, $retrying, 'retrying');
        $this->deliveryFor($school, $failedDelivery, 'failed');
        $this->deliveryFor($school, $deliveredOnly, 'delivered');

        $this->artisan('platform:outbox-prune')->assertSuccessful();

        $this->assertTrue($this->exists($retrying));
        $this->assertTrue($this->exists($failedDelivery));
        $this->assertFalse($this->exists($deliveredOnly));
    }

    #[Test]
    public function a_held_school_is_untouched_and_other_schools_are_pruned(): void
    {
        $held = $this->createSchool();
        $other = $this->createSchool();
        config(['retention.hold_school_ids' => [$held->id]]);
        // E21-RH.6: the database hold is authoritative (a configured hold must also be recorded).
        app(RetentionHolds::class)->place($held->id, 'litigation', 'TEST-HOLD');
        $heldRow = $this->outbox($held, 'dispatched', 200);
        $otherRow = $this->outbox($other, 'dispatched', 200);

        $this->artisan('platform:outbox-prune')->expectsOutputToContain('1 School(s) held')->assertSuccessful();

        $this->assertTrue($this->exists($heldRow));
        $this->assertSame(1, $this->receipts($heldRow));
        $this->assertFalse($this->exists($otherRow));
    }

    #[Test]
    public function the_platform_hold_keeps_school_less_rows(): void
    {
        config(['retention.hold_platform' => true]);
        app(RetentionHolds::class)->place(null, 'regulatory_inquiry', 'TEST-HOLD');
        $platform = $this->outbox(null, 'dispatched', 200);

        $this->artisan('platform:outbox-prune')->assertSuccessful();

        $this->assertTrue($this->exists($platform));
    }

    #[Test]
    public function batches_dry_run_and_reruns_are_safe(): void
    {
        config(['retention.batch_size' => 2]);
        $school = $this->createSchool();
        $rows = collect(range(1, 5))->map(fn () => $this->outbox($school, 'dispatched', 45));

        $this->artisan('platform:outbox-prune', ['--dry-run' => true])->expectsOutputToContain('Dry run: would delete 5 School')->assertSuccessful();
        $rows->each(fn ($id) => $this->assertTrue($this->exists($id)));

        $this->artisan('platform:outbox-prune')->expectsOutputToContain('Deleted 5 School')->assertSuccessful();
        $this->artisan('platform:outbox-prune')->expectsOutputToContain('Deleted 0 School')->assertSuccessful();
        $rows->each(fn ($id) => $this->assertFalse($this->exists($id)));
    }

    #[Test]
    public function the_outbox_and_failed_job_prunes_are_scheduled_daily_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach (['platform:outbox-prune', 'platform:failed-jobs-prune'] as $command) {
            /** @var Event|null $event */
            $event = $events->first(fn (Event $event) => str_contains((string) $event->command, $command));

            $this->assertNotNull($event, "{$command} is not scheduled");
            $this->assertTrue($event->withoutOverlapping, "{$command} must not overlap");
            $this->assertMatchesRegularExpression('/^\d+ \d+ \* \* \*$/', $event->expression, "{$command} must run daily");
        }
    }
}
