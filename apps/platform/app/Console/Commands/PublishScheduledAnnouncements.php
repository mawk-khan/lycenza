<?php

namespace App\Console\Commands;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Models\School;
use App\Support\Observability\SchedulerHeartbeatRecorder;
use App\Support\Tenancy\SchoolNotOperationalException;
use App\Support\Tenancy\SchoolStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 5A.4 §19/§21 -- orchestrates the EXISTING
 * App\Domain\Communications\Application\AnnouncementService::publish()
 * for each due scheduled Announcement; performs no claiming, audience
 * resolution, recipient/delivery creation, or message-body handling
 * itself (brief §19: "do not place message-delivery implementation
 * directly inside the command"). All concurrency safety comes from
 * publish()'s own atomic conditional UPDATE (see that method's
 * docblock) -- this command's per-School `chunk()`/ordering is purely
 * about bounded, deterministic batching (brief §39), not correctness.
 *
 * Mirrors App\Console\Commands\RedispatchDueWebhookDeliveries'/
 * RedispatchDueCommunicationDeliveries' per-School TenantContext
 * pattern exactly (brief §38): each School's due announcements are
 * only ever read/acted on while that School's context is set, and
 * TenantContext::withSchool()'s own finally-block restores/clears
 * context before moving to the next School, so one School's failure
 * or a crash mid-loop cannot leak its context into another School's
 * work.
 */
class PublishScheduledAnnouncements extends Command
{
    protected $signature = 'communications:publish-scheduled {--batch=100 : Maximum due announcements to claim per School per run}';

    protected $description = 'Publish scheduled Announcements whose scheduled_at is due.';

    public function handle(AnnouncementService $announcements, SchedulerHeartbeatRecorder $heartbeats): int
    {
        $batchSize = (int) ($this->option('batch') ?: config('communications.scheduling.batch_size'));
        $totalPublished = 0;
        $totalFailed = 0;

        try {
            // Phase 0N.9 (ADR 0047 section 8): a non-active School's due
            // announcements stay `scheduled` (held) and are published by a
            // normal run after RESUME -- nothing is replayed.
            School::query()->where('status', SchoolStatus::Active->value)->orderBy('id')->chunk(100, function ($schools) use ($announcements, $batchSize, &$totalPublished, &$totalFailed): void {
                foreach ($schools as $school) {
                    app(TenantContext::class)->withSchool($school, function () use ($announcements, $school, $batchSize, &$totalPublished, &$totalFailed): void {
                        [$published, $failed] = $this->publishDueForSchool($announcements, $school, $batchSize);
                        $totalPublished += $published;
                        $totalFailed += $failed;
                    });
                }
            });
        } catch (Throwable $e) {
            $heartbeats->recordFailure('communications-publish-scheduled', $e->getMessage());
            Log::error('platform.communications_publish_scheduled.failed', ['error' => $e->getMessage()]);
            $this->error("Scheduled announcement publication failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $heartbeats->recordSuccess('communications-publish-scheduled');
        $this->info("Published {$totalPublished} scheduled announcement(s), {$totalFailed} failed and backed off.");
        Log::info('platform.communications_publish_scheduled.completed', ['published' => $totalPublished, 'failed' => $totalFailed]);

        return self::SUCCESS;
    }

    /**
     * @return array{0: int, 1: int} [publishedCount, failedCount]
     */
    private function publishDueForSchool(AnnouncementService $announcements, School $school, int $batchSize): array
    {
        // A plain, unlocked read -- deliberately no `lockForUpdate()`/
        // `skipLocked()` here (unlike RedispatchDueWebhookDeliveries):
        // the real concurrency guarantee is publish()'s own atomic
        // claim (brief §21/§22), so two overlapping runs both reading
        // the same candidate ids is harmless -- at most one of them
        // actually publishes each one.
        $due = CommunicationAnnouncement::query()
            ->where('school_id', $school->id)
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit($batchSize)
            ->get();

        $publishedCount = 0;
        $failedCount = 0;

        foreach ($due as $announcement) {
            $actor = $announcement->scheduledBy ?? $announcement->createdBy;

            Log::info('communications.scheduled_publish.started', [
                'school_id' => $school->id,
                'announcement_id' => $announcement->id,
                'scheduled_at' => $announcement->scheduled_at?->toIso8601String(),
            ]);

            try {
                $announcements->publish($announcement, $actor);
                $publishedCount++;

                Log::info('communications.scheduled_publish.completed', [
                    'school_id' => $school->id,
                    'announcement_id' => $announcement->id,
                ]);
            } catch (SchoolNotOperationalException) {
                // Suspended between the School walk and the publish: the
                // publish rolled back and the announcement stays
                // `scheduled` (held) with its schedule untouched -- no
                // backoff, it is published by a normal run after RESUME.
                Log::info('communications.scheduled_publish.held', [
                    'school_id' => $school->id,
                    'announcement_id' => $announcement->id,
                ]);

                return [$publishedCount, $failedCount];
            } catch (Throwable $e) {
                $failedCount++;
                $this->backOff($announcement);

                Log::warning('communications.scheduled_publish.failed', [
                    'school_id' => $school->id,
                    'announcement_id' => $announcement->id,
                    // Safe diagnostic label only -- never the raw
                    // exception message (brief §40: no private
                    // recipient data / message content in logs).
                    'error_class' => $e::class,
                ]);
            }
        }

        return [$publishedCount, $failedCount];
    }

    /**
     * Brief §23: a due announcement whose publish() attempt threw
     * rolls back to `scheduled` (see AnnouncementService::publish()'s
     * docblock) with its ORIGINAL, still-past `scheduled_at` --
     * without this, the next run (one minute later) would immediately
     * retry and likely fail identically, forever, every minute. Pushing
     * `scheduled_at` forward by a bounded backoff turns that into a
     * recoverable, rate-limited retry (a genuinely transient condition
     * -- e.g. an empty audience -- can resolve differently once
     * membership changes) rather than an infinite tight loop. Only
     * updates the row if it is STILL `scheduled` (a defensive
     * conditional UPDATE, not a blind write) -- if it was cancelled or
     * somehow published concurrently between the failed publish() call
     * and this line, this is correctly a no-op.
     */
    private function backOff(CommunicationAnnouncement $announcement): void
    {
        CommunicationAnnouncement::query()
            ->where('id', $announcement->id)
            ->where('status', 'scheduled')
            ->update(['scheduled_at' => now()->addSeconds((int) config('communications.scheduling.failure_backoff_seconds'))]);
    }
}
