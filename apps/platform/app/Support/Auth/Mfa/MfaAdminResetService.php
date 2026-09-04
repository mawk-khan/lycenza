<?php

namespace App\Support\Auth\Mfa;

use App\Models\User;
use App\Models\UserMfaRecoveryCode;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 0H.4D-P1 section 19: NOT a School-admin action. User identity
 * is platform-global (one User can hold SchoolMembership rows across
 * many Schools -- CLAUDE.md rule 19/20, and SchoolMembership's own
 * docblock), so a School admin resetting a User's MFA would carry
 * cross-School blast radius on an object it doesn't own. The caller
 * (MfaAdminController) is responsible for the actual authorization
 * check via `platform.users.mfa.reset` (AuthorizesCapability::
 * authorizeCapability(..., platform: true)) BEFORE calling this --
 * this service assumes the caller is already authorized and only
 * performs the reset + audit.
 */
class MfaAdminResetService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function reset(User $actor, User $target): void
    {
        DB::transaction(function () use ($target) {
            $target->mfaFactors()->where('status', 'active')->update(['status' => 'revoked']);
            $target->mfaFactors()->where('status', 'pending')->delete();

            UserMfaRecoveryCode::query()
                ->where('user_id', $target->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);
        });

        $this->audit->platform(MfaAuditActions::RESET_BY_ADMIN, actor: $actor, subject: $target);
    }
}
