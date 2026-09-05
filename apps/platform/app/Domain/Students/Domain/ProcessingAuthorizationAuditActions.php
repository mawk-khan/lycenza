<?php

namespace App\Domain\Students\Domain;

/**
 * Phase 0H.4D-P2 -- the closed vocabulary of School-audited actions
 * for the processing-authorization ledger (mirrors
 * App\Support\Auth\Mfa\MfaAuditActions' shape). Every call site is in
 * StudentProcessingAuthorizationService; metadata at each site is
 * bounded to ids/purpose/basis_type/status, never DOB, Guardian/
 * Student names, or note text.
 */
final class ProcessingAuthorizationAuditActions
{
    public const RECORDED = 'students.processing_authorization.recorded';

    public const WITHDRAWN = 'students.processing_authorization.withdrawn';

    public const REVOKED = 'students.processing_authorization.revoked';

    public const SUPERSEDED = 'students.processing_authorization.superseded';
}
