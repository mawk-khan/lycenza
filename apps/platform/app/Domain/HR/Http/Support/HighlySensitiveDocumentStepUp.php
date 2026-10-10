<?php

namespace App\Domain\HR\Http\Support;

use App\Models\School;
use App\Models\User;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\AuthorizesCapability;
use Illuminate\Http\Request;

/**
 * SR.4 (ADR 0071 §26.7): a write that touches a `highly_sensitive` HR
 * document record (register, re-classify into or out of the tier, or
 * archive one) needs a fresh MFA code -- the same transition rule
 * EmployeeDocumentService::assertClassificationCapability() uses to require
 * `hr.employees.sensitive.manage`. That capability is checked FIRST (a
 * caller without it gets the service's 403, never burns a code); the
 * service still decides authorization on its own. Restricted-tier writes
 * are unchanged: no step-up.
 */
class HighlySensitiveDocumentStepUp
{
    use AuthorizesCapability;

    public function __construct(private readonly FreshMfaRequirement $mfa) {}

    public function requireFor(Request $request, User $actor, School $school, ?string $targetTier, ?string $currentTier): void
    {
        if ($targetTier !== 'highly_sensitive' && $currentTier !== 'highly_sensitive') {
            return;
        }

        $this->authorizeCapabilityFor($actor, 'hr.employees.sensitive.manage', $school);
        $this->mfa->requireForAction($request, $actor);
    }
}
