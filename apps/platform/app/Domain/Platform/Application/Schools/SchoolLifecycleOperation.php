<?php

namespace App\Domain\Platform\Application\Schools;

/**
 * Phase 0N.9 (ADR 0047 section 7): every privileged School lifecycle and
 * bootstrap operation. Each needs `platform.schools.manage`, a fresh
 * in-session MFA re-verification and explicit confirmation.
 */
enum SchoolLifecycleOperation: string
{
    case Create = 'create';
    case ReplaceBootstrapAdmin = 'replace_bootstrap_admin';
    case Activate = 'activate';
    case Suspend = 'suspend';
    case Resume = 'resume';
}
