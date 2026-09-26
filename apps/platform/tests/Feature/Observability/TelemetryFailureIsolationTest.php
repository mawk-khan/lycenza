<?php

namespace Tests\Feature\Observability;

use App\Models\DomainEventOutbox;
use App\Support\Observability\Metrics\MetricStore;
use App\Support\Observability\Metrics\StoreMetricsRecorder;
use App\Support\Observability\MetricsRecorder;
use App\Support\Settings\SchoolSettingsService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * Phase 0O.5A (ADR 0051 §10, §16): the metrics store failing (Redis down,
 * collector absent) never changes a business outcome -- no HTTP error, no
 * rolled-back transaction, no retried job, no telemetry queue.
 */
class TelemetryFailureIsolationTest extends TestCase
{
    use CreatesTenancyFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $failing = new class implements MetricStore
        {
            public int $attempts = 0;

            public function increment(string $series, float $by): void
            {
                $this->attempts++;
                throw new RuntimeException('metrics store down');
            }

            public function set(string $series, float $value): void
            {
                $this->attempts++;
                throw new RuntimeException('metrics store down');
            }

            public function all(): array
            {
                throw new RuntimeException('metrics store down');
            }

            public function flush(): void {}
        };

        $this->app->instance(MetricStore::class, $failing);
        $this->app->forgetInstance(MetricsRecorder::class);
        $this->app->singleton(MetricsRecorder::class, fn () => new StoreMetricsRecorder($failing));
    }

    #[Test]
    public function an_http_request_and_its_transaction_are_unaffected(): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        $this->put('/app/settings', ['name' => 'Telemetry Down School', 'timezone' => 'Asia/Kolkata', 'default_locale' => 'en'])
            ->assertRedirect('/app/settings');

        $this->assertSame('Telemetry Down School', $school->fresh()->name, 'the business transaction committed');
        $this->getJson('/api/health/live')->assertOk();
    }

    #[Test]
    public function queued_and_recovery_work_complete_and_nothing_is_retried(): void
    {
        [, $school] = $this->createSchoolAdmin('school_admin');
        app(SchoolSettingsService::class)->set($school, 'communications.digest_frequency', 'weekly');
        $event = DomainEventOutbox::query()->where('school_id', $school->id)->latest('occurred_at')->firstOrFail();

        $this->assertSame(0, Artisan::call('platform:outbox-dispatch'), 'dispatch + reconciliation succeed');
        $this->assertNotNull($event->fresh()->processed_at, 'the job (sync) ran to completion and acknowledged');
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'no job failed or was retried because of telemetry');
        $this->assertSame(0, Artisan::call('platform:recover-queued-work'));
        $this->assertGreaterThan(0, app(MetricStore::class)->attempts, 'telemetry was attempted and failed -- the proof is not vacuous');
    }

    #[Test]
    public function the_recorder_never_throws_on_a_store_failure(): void
    {
        $recorder = app(MetricsRecorder::class);

        $recorder->counter('lycenza_http_requests_total', 1, ['request_surface' => 'web', 'status_class' => '2xx']);
        $recorder->observe('lycenza_http_request_duration_seconds', 0.2, ['request_surface' => 'web']);
        $recorder->gauge('lycenza_verification_last_result', 1, ['check' => 'verify_storage']);

        $this->assertTrue(true, 'no exception escaped');
    }
}
