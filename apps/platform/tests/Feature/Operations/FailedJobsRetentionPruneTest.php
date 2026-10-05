<?php

namespace Tests\Feature\Operations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CommitsRetentionFixtures;
use Tests\TestCase;

/**
 * E21.2A (E21-D13): `platform:failed-jobs-prune` deletes failed jobs
 * FAILED_JOBS_RETENTION_DAYS after their terminal failure, on a fixed clock.
 */
class FailedJobsRetentionPruneTest extends TestCase
{
    use CommitsRetentionFixtures;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2027-06-15 12:00:00');
        $this->travelTo($this->now);
        config(['retention.failed_jobs_days' => 30]);
    }

    private function failedJob(int $ageSeconds): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid, 'connection' => 'redis', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'TestJob']), 'exception' => 'RuntimeException: test',
            'failed_at' => $this->now->copy()->subSeconds($ageSeconds),
        ]);

        return $uuid;
    }

    private function exists(string $uuid): bool
    {
        return DB::table('failed_jobs')->where('uuid', $uuid)->exists();
    }

    #[Test]
    public function nothing_is_deleted_while_retention_is_unconfigured_or_invalid(): void
    {
        $old = $this->failedJob(400 * 86400);

        config(['retention.failed_jobs_days' => null]);
        $this->artisan('platform:failed-jobs-prune')->expectsOutputToContain('not configured')->assertSuccessful();

        config(['retention.failed_jobs_days' => 'abc']);
        $this->artisan('platform:failed-jobs-prune')->assertFailed();

        $this->assertTrue($this->exists($old));
    }

    #[Test]
    public function failed_jobs_past_the_period_are_deleted_and_younger_ones_kept(): void
    {
        $old = $this->failedJob(30 * 86400 + 1);
        $boundary = $this->failedJob(30 * 86400);
        $young = $this->failedJob(86400);

        $this->artisan('platform:failed-jobs-prune', ['--dry-run' => true])->expectsOutputToContain('Dry run: would delete 1 failed job(s)')->assertSuccessful();
        $this->assertTrue($this->exists($old));

        $this->artisan('platform:failed-jobs-prune')->expectsOutputToContain('Deleted 1 failed job(s)')->assertSuccessful();
        $this->artisan('platform:failed-jobs-prune')->expectsOutputToContain('Deleted 0 failed job(s)')->assertSuccessful();

        $this->assertFalse($this->exists($old));
        $this->assertTrue($this->exists($boundary));
        $this->assertTrue($this->exists($young));
    }
}
