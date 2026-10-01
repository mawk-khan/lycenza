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
    // E21.2F (E21-D11): freeze a School for good, and withdraw a mistaken closure.
    case Close = 'close';
    case Reopen = 'reopen';
}
