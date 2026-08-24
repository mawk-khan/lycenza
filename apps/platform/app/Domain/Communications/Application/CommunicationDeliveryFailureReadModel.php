<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5A.8 §18/§19/§41/§50 -- the operational "delivery attention"
 * surface: which published Announcements have at least one FAILED
 * delivery, with a per-channel/failure-code breakdown. Single-domain
 * (Announcement), so unlike CommunicationInboxReadModel this uses
 * real, correctness-safe `paginate()` -- no mixed-domain pagination
 * tradeoff here (brief §31).
 *
 * Scoped to Announcements only: conversation messages only ever use
 * the IN_APP channel, which -- per Phase 5A.1's own design note on
 * CommunicationDeliveryResult::delivered() -- "can never transiently
 * fail," so a real FAILED delivery in this schema is, in practice,
 * always an Announcement EMAIL delivery today. Never mixes
 * SUPPRESSED (a policy decision, `communication_delivery_policy_decisions`)
 * with FAILED (an attempted-and-failed delivery, `communication_deliveries`)
 * -- see brief §19.
 */
class CommunicationDeliveryFailureReadModel
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return LengthAwarePaginator<int, CommunicationAnnouncement>
     */
    public function failedAnnouncements(School $school, int $perPage = 20, int $page = 1): LengthAwarePaginator
    {
        return $this->context->withSchool($school, function () use ($perPage, $page) {
            $announcementIdsWithFailures = DB::table('communication_deliveries as cd')
                ->join('communication_recipients as cr', 'cr.id', '=', 'cd.recipient_id')
                ->join('communication_messages as cm', 'cm.id', '=', 'cr.message_id')
                ->whereNotNull('cm.announcement_id')
                ->where('cd.status', 'failed')
                ->distinct()
                ->pluck('cm.announcement_id');

            return CommunicationAnnouncement::query()
                ->whereIn('id', $announcementIdsWithFailures)
                ->with('createdBy:id,name')
                ->orderByDesc('published_at')
                ->paginate($perPage, page: $page);
        });
    }

    /**
     * One batched breakdown query for a whole PAGE of announcements
     * (never one query per announcement).
     *
     * @param  array<int, string>  $announcementIds
     * @return array<string, array<int, array{channel: string, failureCode: ?string, count: int}>>
     */
    public function failureBreakdown(School $school, array $announcementIds): array
    {
        if ($announcementIds === []) {
            return [];
        }

        return $this->context->withSchool($school, function () use ($announcementIds) {
            $rows = DB::table('communication_deliveries as cd')
                ->join('communication_recipients as cr', 'cr.id', '=', 'cd.recipient_id')
                ->join('communication_messages as cm', 'cm.id', '=', 'cr.message_id')
                ->whereIn('cm.announcement_id', $announcementIds)
                ->where('cd.status', 'failed')
                ->select('cm.announcement_id', 'cd.channel', 'cd.failure_code', DB::raw('count(*) as failed_count'))
                ->groupBy('cm.announcement_id', 'cd.channel', 'cd.failure_code')
                ->get();

            $breakdown = [];

            foreach ($rows as $row) {
                $breakdown[$row->announcement_id][] = [
                    'channel' => $row->channel,
                    'failureCode' => $row->failure_code,
                    'count' => (int) $row->failed_count,
                ];
            }

            return $breakdown;
        });
    }
}
