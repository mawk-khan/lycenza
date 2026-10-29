<?php

namespace App\Domain\Platform\Application\Groups;

use App\Models\SchoolGroup;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\Mfa\FreshMfaProof;
use App\Support\Presentation\BrandColor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * ORG.12F (ADR 0080 §11.7, OD-12.17): the ONE writer of a Network's brand
 * (label, primary accent) -- `school_group_brand_profiles`, a Group-owned
 * platform table.
 *
 * - Authority: the platform or a whole-Group Network Manager of THIS Group
 *   (GroupGovernanceAccess, re-read inside the transaction).
 * - One change may restyle every School that explicitly opted in, so each
 *   change needs a closed reason and a fresh MFA proof, refuses a stale form
 *   (version), and is audited on the platform ledger (changed fields only).
 * - It never touches a School: no School row, preference or identity is
 *   written, and no School is ever opted in from here.
 */
class NetworkBrandService
{
    public const MAX_LABEL = 60;

    public function __construct(
        private readonly GroupGovernanceAccess $access,
        private readonly SchoolGroupGovernanceService $groups,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array{label: ?string, accent: ?string, version: int} */
    public function current(SchoolGroup $group): array
    {
        $row = DB::table('school_group_brand_profiles')->where('school_group_id', $group->id)->first(['display_label', 'primary_accent', 'version']);

        return ['label' => $row?->display_label, 'accent' => $row?->primary_accent, 'version' => (int) ($row->version ?? 0)];
    }

    public function assertMayManage(User $actor, SchoolGroup $group): void
    {
        if (! $this->access->mayManage($actor, $group)) {
            throw new AccessDeniedHttpException('This account cannot manage this Network brand.');
        }
    }

    /** @return list<string> changed fields */
    public function update(User $actor, SchoolGroup $group, ?string $label, ?string $accent, int $expectedVersion, NetworkBrandChangeReason $reason, FreshMfaProof $mfa): array
    {
        $this->assertMayManage($actor, $group);
        if (! $mfa->isFor($actor->id)) {
            throw new AuthorizationException('This action is unauthorized.');
        }
        $label = $label === null ? null : trim((string) preg_replace('/\s+/u', ' ', $label));
        $label = $label === '' ? null : $label;
        if ($label !== null && (mb_strlen($label) > self::MAX_LABEL || preg_match('/[<>\p{Cc}\p{Cf}]/u', $label) === 1)) {
            throw ValidationException::withMessages(['display_label' => 'Use plain text of at most 60 characters, without < or >.']);
        }
        $accent = BrandColor::normalize($accent);
        if ($accent !== null && ! BrandColor::isValid($accent)) {
            throw ValidationException::withMessages(['primary_accent' => 'Use a colour like #1D4E89.']);
        }

        return DB::transaction(function () use ($actor, $group, $label, $accent, $expectedVersion, $reason, $mfa): array {
            $this->groups->requireActiveLocked($group);
            $authority = $this->access->requireManageNow($actor, $group);
            $row = DB::table('school_group_brand_profiles')->where('school_group_id', $group->id)->lockForUpdate()->first();
            if ((int) ($row->version ?? 0) !== $expectedVersion) {
                throw ValidationException::withMessages(['brand' => 'The Network brand changed since you opened this page. Reload and try again.']);
            }
            $after = ['display_label' => $label, 'primary_accent' => $accent];
            $changed = array_keys(array_filter($after, fn ($value, string $field): bool => $value !== ($row->{$field} ?? null), ARRAY_FILTER_USE_BOTH));
            if ($changed === []) {
                return [];
            }
            if ($row === null) {
                DB::table('school_group_brand_profiles')->insert([...$after, 'id' => (string) Str::uuid7(), 'school_group_id' => $group->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                DB::table('school_group_brand_profiles')->where('id', $row->id)->update([...$after, 'version' => $row->version + 1, 'updated_at' => now()]);
            }
            $this->audit->platform('platform.school_group.brand_updated', actor: $actor, subject: $group, metadata: [
                'changed' => $changed,
                'reason_code' => $reason->value,
                'mfa_verified_at' => $mfa->verifiedAt->toIso8601String(),
                'authority' => $authority,
            ]);

            return $changed;
        });
    }
}
