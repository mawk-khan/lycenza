<?php

namespace App\Domain\Communications\Application\Audience;

use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5A.2 §9: every active SchoolMembership belonging to the
 * Announcement's School, excluding its creator. `campus_id` is
 * deliberately NOT a resolution filter here -- no reliable
 * Campus<->SchoolMembership relationship exists yet (see the phase
 * doc); a School-wide audience really is every eligible member,
 * regardless of the announcement's informational campus tag.
 *
 * A single LEFT JOIN query (not a per-membership N+1, and not an
 * unbounded `whereIn()` list) builds both the deduplicated user id
 * list and the role-name breakdown for §14's preview at once --
 * documented scaling boundary in the phase doc (§19): safe for a
 * single-School's realistic membership count today; true bulk/
 * chunked campaign-scale resolution is deferred.
 */
class SchoolWideAudienceResolver implements CommunicationAudienceResolver
{
    public function __construct(private readonly TenantContext $context) {}

    public function type(): CommunicationAudienceType
    {
        return CommunicationAudienceType::SchoolWide;
    }

    public function resolve(CommunicationAnnouncement $announcement): ResolvedAudience
    {
        return $this->context->withSchool($announcement->school, function () use ($announcement) {
            $rows = DB::table('school_memberships as sm')
                ->leftJoin('membership_role_assignments as mra', function ($join) use ($announcement): void {
                    $join->on('mra.school_membership_id', '=', 'sm.id')
                        ->where('mra.school_id', $announcement->school_id);
                })
                ->leftJoin('roles as r', 'r.id', '=', 'mra.role_id')
                ->where('sm.school_id', $announcement->school_id)
                ->where('sm.status', 'active')
                ->where('sm.user_id', '!=', $announcement->created_by_user_id)
                ->orderBy('sm.id')
                ->get(['sm.user_id', 'r.name as role_name']);

            $userIds = [];
            $breakdown = [];
            $seen = [];

            foreach ($rows as $row) {
                // A membership can carry >1 role; the first row wins
                // for the breakdown label (a defensible simplification
                // for a foundation-level preview -- see the phase doc).
                if (isset($seen[$row->user_id])) {
                    continue;
                }
                $seen[$row->user_id] = true;

                $userIds[] = $row->user_id;
                $label = $row->role_name ?? 'Other Members';
                $breakdown[$label] = ($breakdown[$label] ?? 0) + 1;
            }

            return new ResolvedAudience($userIds, $breakdown);
        });
    }
}
