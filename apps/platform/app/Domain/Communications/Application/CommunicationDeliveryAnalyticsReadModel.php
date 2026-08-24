<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryAttempt;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5A.11 §15-23/§41-43 -- aggregates delivery OUTCOMES from the
 * canonical evidence Phase 5A.1-5A.10 already produced
 * (communication_deliveries, communication_delivery_attempts,
 * communication_delivery_policy_decisions,
 * communication_announcement_recipients) -- never a second parallel
 * event/summary table (brief §6/§7). Answers "what happened to
 * recipient/channel delivery after publication" -- distinct from
 * App\Domain\Communications\Application\CommunicationAuditReadModel
 * (brief §4).
 *
 * Every method here issues a small, FIXED number of grouped aggregate
 * queries regardless of recipient/delivery volume (brief §42/§66) --
 * never one query per recipient. Reuses
 * App\Domain\Communications\Application\CommunicationDeliveryFailureReadModel
 * for the failure breakdown itself (brief §54: one canonical failure
 * definition, not a second copy).
 *
 * Terminology (brief §17/§38): EMAIL `sent` means "accepted by the
 * configured application mail transport" -- never presented as
 * "delivered" anywhere this read model's output reaches the UI.
 */
class CommunicationDeliveryAnalyticsReadModel
{
    /**
     * Phase 5A.9's closed status set, bucketed for presentation. A
     * status not listed here (there is none in the current CHECK
     * constraint) would fall through `inProgress` -- see
     * `bucketForStatus()`.
     */
    private const SUCCESS_STATUSES = ['delivered', 'read', 'sent', 'accepted'];

    private const FAILURE_STATUSES = ['failed', 'bounced', 'rejected', 'expired'];

    private const IN_PROGRESS_STATUSES = ['pending', 'queued', 'sending'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly CommunicationDeliveryFailureReadModel $failureReadModel,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function announcementSummary(School $school, CommunicationAnnouncement $announcement): array
    {
        return $this->context->withSchool($school, function () use ($school, $announcement) {
            if ($announcement->message_id === null) {
                // Brief §35: never published, so no delivery evidence can
                // exist yet -- an honest empty summary, not a fabricated
                // zero-filled one pretending delivery happened.
                return [
                    'recipients' => 0,
                    'channels' => [],
                    'deferredQuietHours' => [],
                    'failures' => [],
                    'emergency' => null,
                ];
            }

            $channelRows = DB::table('communication_deliveries as cd')
                ->join('communication_recipients as cr', 'cr.id', '=', 'cd.recipient_id')
                ->where('cr.message_id', $announcement->message_id)
                ->select('cd.channel', 'cd.status', DB::raw('count(*) as delivery_count'))
                ->groupBy('cd.channel', 'cd.status')
                ->get();

            $deferredRows = DB::table('communication_deliveries as cd')
                ->join('communication_recipients as cr', 'cr.id', '=', 'cd.recipient_id')
                ->where('cr.message_id', $announcement->message_id)
                ->where('cd.status', 'queued')
                ->where('cd.attempts', 0)
                ->select('cd.channel', DB::raw('count(*) as deferred_count'))
                ->groupBy('cd.channel')
                ->get();

            $suppressedRows = DB::table('communication_delivery_policy_decisions')
                ->where('message_id', $announcement->message_id)
                ->select('channel', 'reason', DB::raw('count(*) as decision_count'))
                ->groupBy('channel', 'reason')
                ->get();

            $channels = $this->buildChannelBreakdown($channelRows, $deferredRows, $suppressedRows);

            $failures = $this->failureReadModel->failureBreakdown($school, [$announcement->id])[$announcement->id] ?? [];

            return [
                'recipients' => $announcement->recipient_count ?? 0,
                'channels' => $channels,
                'deferredQuietHours' => $deferredRows->mapWithKeys(fn ($row) => [$row->channel => (int) $row->deferred_count])->all(),
                'failures' => $failures,
                'emergency' => $this->emergencyEvidence($announcement, $channels),
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function schoolOverview(School $school, Carbon $fromUtc, Carbon $toUtc): array
    {
        return $this->context->withSchool($school, function () use ($school, $fromUtc, $toUtc) {
            $announcementRows = DB::table('communication_announcements')
                ->where('school_id', $school->id)
                ->where('status', 'published')
                ->whereBetween('published_at', [$fromUtc, $toUtc])
                ->select('dispatch_mode', DB::raw('count(*) as announcement_count'), DB::raw('coalesce(sum(recipient_count), 0) as recipient_total'))
                ->groupBy('dispatch_mode')
                ->get();

            $published = (int) $announcementRows->sum('announcement_count');
            $recipients = (int) $announcementRows->sum('recipient_total');
            $emergencyRow = $announcementRows->firstWhere('dispatch_mode', 'emergency');
            $emergencyAnnouncements = $emergencyRow === null ? 0 : (int) $emergencyRow->announcement_count;

            $channelRows = DB::table('communication_deliveries as cd')
                ->join('communication_recipients as cr', 'cr.id', '=', 'cd.recipient_id')
                ->join('communication_messages as cm', 'cm.id', '=', 'cr.message_id')
                ->join('communication_announcements as ca', 'ca.id', '=', 'cm.announcement_id')
                ->where('ca.school_id', $school->id)
                ->where('ca.status', 'published')
                ->whereBetween('ca.published_at', [$fromUtc, $toUtc])
                ->select('cd.channel', 'cd.status', DB::raw('count(*) as delivery_count'))
                ->groupBy('cd.channel', 'cd.status')
                ->get();

            $deferredRows = DB::table('communication_deliveries as cd')
                ->join('communication_recipients as cr', 'cr.id', '=', 'cd.recipient_id')
                ->join('communication_messages as cm', 'cm.id', '=', 'cr.message_id')
                ->join('communication_announcements as ca', 'ca.id', '=', 'cm.announcement_id')
                ->where('ca.school_id', $school->id)
                ->where('ca.status', 'published')
                ->whereBetween('ca.published_at', [$fromUtc, $toUtc])
                ->where('cd.status', 'queued')
                ->where('cd.attempts', 0)
                ->count();

            $suppressedRows = DB::table('communication_delivery_policy_decisions as cdpd')
                ->join('communication_messages as cm', 'cm.id', '=', 'cdpd.message_id')
                ->join('communication_announcements as ca', 'ca.id', '=', 'cm.announcement_id')
                ->where('ca.school_id', $school->id)
                ->where('ca.status', 'published')
                ->whereBetween('ca.published_at', [$fromUtc, $toUtc])
                ->count();

            $bypassEvents = SchoolAuditEvent::query()
                ->where('school_id', $school->id)
                ->where('event_type', 'communication.emergency_quiet_hours_bypass_used')
                ->whereBetween('occurred_at', [$fromUtc, $toUtc])
                ->count();

            $channels = $this->buildChannelBreakdown($channelRows, collect(), collect());

            return [
                'published' => $published,
                'recipients' => $recipients,
                'channels' => $channels,
                'deferredQuietHours' => $deferredRows,
                'suppressed' => $suppressedRows,
                'emergency' => [
                    'announcements' => $emergencyAnnouncements,
                    'bypassEvents' => $bypassEvents,
                ],
            ];
        });
    }

    /**
     * Phase 5A.11 §28 -- authorized paginated drill-down. Never exposes
     * a raw email destination or provider payload -- name and safe
     * delivery/attempt metadata only (brief §28/§72).
     *
     * @param  array{channel?: ?string, status?: ?string, failureCode?: ?string}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function deliveryDetail(School $school, CommunicationAnnouncement $announcement, array $filters, int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        return $this->context->withSchool($school, function () use ($announcement, $filters, $perPage, $page) {
            if ($announcement->message_id === null) {
                return new LengthAwarePaginator([], 0, $perPage, $page);
            }

            $query = CommunicationDelivery::query()
                ->whereHas('recipient', fn ($q) => $q->where('message_id', $announcement->message_id))
                ->with('recipient.recipientUser:id,name');

            if (in_array($filters['channel'] ?? null, ['in_app', 'email', 'sms', 'whatsapp', 'push'], true)) {
                $query->where('channel', $filters['channel']);
            }

            if (is_string($filters['status'] ?? null) && $filters['status'] !== '') {
                $query->where('status', $filters['status']);
            }

            if (is_string($filters['failureCode'] ?? null) && $filters['failureCode'] !== '') {
                $query->where('failure_code', $filters['failureCode']);
            }

            $paginated = $query->orderByDesc('created_at')->paginate($perPage, page: $page);

            return $paginated->through(fn (CommunicationDelivery $delivery) => $this->presentDeliveryRow($delivery));
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDeliveryRow(CommunicationDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'recipientName' => $delivery->recipient?->recipientUser?->name,
            'channel' => $delivery->channel,
            'status' => $delivery->status,
            'failureCode' => $delivery->failure_code,
            'attempts' => $delivery->attempts,
            'queuedAt' => $delivery->queued_at?->toIso8601String(),
            'sentAt' => $delivery->sent_at?->toIso8601String(),
            'readAt' => $delivery->read_at?->toIso8601String(),
            'failedAt' => $delivery->failed_at?->toIso8601String(),
        ];
    }

    /**
     * Phase 5A.11 §23/§47/§48 -- append-only attempt history for ONE
     * delivery, never mutated. Bounded by
     * config('communications.delivery.max_attempts') -- never paginated
     * separately, the same "small, fixed" reasoning as every other
     * method here.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function attemptHistory(School $school, CommunicationDelivery $delivery): Collection
    {
        return $this->context->withSchool($school, fn () => $delivery->attemptsHistory()
            ->orderBy('attempt_number')
            ->get()
            ->map(fn (CommunicationDeliveryAttempt $attempt) => $this->presentAttempt($attempt))
            ->values());
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAttempt(CommunicationDeliveryAttempt $attempt): array
    {
        return [
            'attemptNumber' => $attempt->attempt_number,
            'outcome' => $attempt->outcome,
            'failureCode' => $attempt->failure_code,
            'startedAt' => $attempt->started_at->toIso8601String(),
            'completedAt' => $attempt->completed_at?->toIso8601String(),
            'durationMs' => $attempt->duration_ms,
        ];
    }

    /**
     * Phase 5A.11 §16-19/§50/§51 -- the ONE place a channel's raw
     * grouped rows become the presentation bucket shape. IN_APP:
     * planned (every row) / available (delivered+read -- brief §50's
     * "eligible IN_APP recipients/deliveries" denominator) / read /
     * unread. Every other channel: planned / sent / failed /
     * inProgress / suppressed -- `sent` is deliberately never labelled
     * "delivered" here (brief §17/§38).
     *
     * `$channelRows`/`$deferredRows`/`$suppressedRows` are raw
     * `DB::table(...)->get()` results (stdClass rows) -- not typed
     * more precisely than plain Collections since PHPStan cannot
     * verify dynamic stdClass property shapes across a query builder
     * boundary.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildChannelBreakdown(Collection $channelRows, Collection $deferredRows, Collection $suppressedRows): array
    {
        $channels = [];

        $channelNames = $channelRows->pluck('channel')
            ->merge($suppressedRows->pluck('channel'))
            ->unique();

        foreach ($channelNames as $channel) {
            $rowsForChannel = $channelRows->where('channel', $channel);
            $planned = (int) $rowsForChannel->sum('delivery_count');
            $suppressedCount = (int) $suppressedRows->where('channel', $channel)->sum('decision_count');

            if ($channel === 'in_app') {
                $available = (int) $rowsForChannel->whereIn('status', ['delivered', 'read'])->sum('delivery_count');
                $read = (int) $rowsForChannel->where('status', 'read')->sum('delivery_count');

                $channels[$channel] = [
                    'planned' => $planned,
                    'available' => $available,
                    'read' => $read,
                    'unread' => max(0, $available - $read),
                ];

                continue;
            }

            $sent = (int) $rowsForChannel->whereIn('status', self::SUCCESS_STATUSES)->sum('delivery_count');
            $failed = (int) $rowsForChannel->whereIn('status', self::FAILURE_STATUSES)->sum('delivery_count');
            $inProgress = (int) $rowsForChannel->whereIn('status', self::IN_PROGRESS_STATUSES)->sum('delivery_count');

            $channels[$channel] = [
                'planned' => $planned,
                'sent' => $sent,
                'failed' => $failed,
                'inProgress' => $inProgress,
                'suppressed' => $suppressedCount,
            ];
        }

        return $channels;
    }

    /**
     * Phase 5A.11 §21/§63 -- governance/timing evidence only, never
     * presented as a delivery-success metric (brief §21). `null` for a
     * non-Emergency announcement -- there is nothing to report.
     *
     * @param  array<string, array<string, mixed>>  $channels
     * @return array<string, mixed>|null
     */
    private function emergencyEvidence(CommunicationAnnouncement $announcement, array $channels): ?array
    {
        if (! $announcement->isEmergency()) {
            return null;
        }

        $bypassChannels = SchoolAuditEvent::query()
            ->where('subject_type', CommunicationAnnouncement::class)
            ->where('subject_id', $announcement->id)
            ->where('event_type', 'communication.emergency_quiet_hours_bypass_used')
            ->get()
            ->map(fn (SchoolAuditEvent $event) => (string) ($event->metadata['channel'] ?? ''))
            ->filter()
            ->unique()
            ->values();

        return [
            'declaredByUserId' => $announcement->emergency_declared_by_user_id,
            'declaredAt' => $announcement->emergency_declared_at?->toIso8601String(),
            'quietHoursBypassChannels' => $bypassChannels->all(),
            'eligibleDeliveryCounts' => $bypassChannels
                ->mapWithKeys(fn (string $channel) => [$channel => $channels[$channel]['planned'] ?? 0])
                ->all(),
        ];
    }
}
