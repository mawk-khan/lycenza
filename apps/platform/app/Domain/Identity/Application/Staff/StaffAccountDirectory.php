<?php

namespace App\Domain\Identity\Application\Staff;

use App\Domain\Identity\Infrastructure\StaffAccountInvitation;
use App\Domain\Identity\Infrastructure\StaffAccountInvitationRole;
use App\Models\MembershipRoleAssignment;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Support\Tenancy\TenantContext;

/**
 * Phase 0O.12B (ADR 0059 section 23, owner amendment): the Staff accounts
 * page's read model -- this School's OWN facts only. A staff account is a
 * membership that has held a School role (active or revoked); a Guardian's
 * membership never appears. Shown: name, the address the School invited or
 * its member's address, status, active roles, and pending/recent
 * invitations. Never another School, a platform or Group role, a password,
 * a token or HR data.
 *
 * SR.2 (ADR 0071 §10.4): role assignments -- a member's active roles and the
 * roles an invitation carries -- are shown only to a viewer holding
 * `school.roles.view` ($withRoles); `school.members.view` alone lists who has
 * staff access, not with which roles.
 */
final class StaffAccountDirectory
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return array{staff: list<array<string, mixed>>, invitations: list<array<string, mixed>>}
     */
    public function for(School $school, bool $withRoles = true): array
    {
        return $this->context->withSchool($school, function () use ($school, $withRoles): array {
            // POR.1: staff = `school`-scope grants only; a Guardian's portal grant never lists them here.
            $grants = MembershipRoleAssignment::query()
                ->where('school_id', $school->id)
                ->staff()
                ->with('role')
                ->get()
                ->groupBy('school_membership_id');

            $memberships = SchoolMembership::query()
                ->where('school_id', $school->id)
                ->whereIn('id', $grants->keys()->all())
                ->with('user')
                ->get()
                ->sortBy(fn (SchoolMembership $m) => [$m->status !== SchoolMembership::STATUS_ACTIVE, mb_strtolower((string) $m->user?->name)])
                ->values();

            $staff = $memberships->map(function (SchoolMembership $m) use ($grants, $withRoles): array {
                $active = $grants->get($m->id, collect())->filter(fn (MembershipRoleAssignment $g) => $g->isActive());

                return [
                    'membershipId' => $m->id,
                    'userId' => $m->user_id,
                    'name' => $m->user?->name,
                    // E21.4: a minimized former member shows as "Former user", with no address.
                    'email' => $m->user?->publicEmail(),
                    'status' => $m->status,
                    'roles' => $withRoles ? $active->map(fn (MembershipRoleAssignment $g) => ['key' => $g->role->key, 'name' => $g->role->name])->sortBy('name')->values()->all() : [],
                    'joinedAt' => $m->joined_at?->toIso8601String(),
                ];
            })->all();

            $invitations = StaffAccountInvitation::query()
                ->where('school_id', $school->id)
                ->where(fn ($q) => $q->where('status', StaffAccountInvitation::STATUS_PENDING)
                    ->orWhere('updated_at', '>=', now()->subDays(7)))
                ->orderByDesc('created_at')
                ->limit(200)
                ->get();

            $roleNames = StaffAccountInvitationRole::query()
                ->whereIn('staff_account_invitation_id', $invitations->pluck('id')->all())
                ->with('role')
                ->get()
                ->groupBy('staff_account_invitation_id');

            return [
                'staff' => $staff,
                'invitations' => $invitations->map(fn (StaffAccountInvitation $i) => [
                    'id' => $i->id,
                    'email' => $i->destination_email,
                    'status' => $i->effectiveStatus(),
                    'roles' => $withRoles ? $roleNames->get($i->id, collect())->map(fn (StaffAccountInvitationRole $r) => $r->role->name)->sort()->values()->all() : [],
                    'expiresAt' => $i->expires_at->toIso8601String(),
                    'createdAt' => $i->created_at->toIso8601String(),
                ])->values()->all(),
            ];
        });
    }
}
