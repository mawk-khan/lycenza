<?php

namespace App\Domain\Admissions\Application\Exceptions;

/**
 * Phase 1D.3 / `docs/modules/ADMISSIONS.md` §9.2's "explicit create-vs-
 * link decision" rule, applied at conversion: when `create` mode
 * supplies a contact value that
 * `App\Domain\Guardians\Application\GuardianContactService::
 * findCandidatesBySchool()`'s existing exact-match lookup finds one or
 * more candidates for, `AdmissionConversionService` refuses to blindly
 * create a new Guardian -- it never auto-links (no fuzzy/name matching,
 * no silent candidate selection) and never partially creates canonical
 * records while waiting on a choice. Staff must resubmit the
 * conversion command as `link_existing` against the (or one of the)
 * discovered candidate(s) instead. `$candidateCount` is diagnostic only
 * (never candidate ids/names -- those are not PII themselves but this
 * exception's message stays generic regardless).
 */
class AdmissionGuardianSelectionRequiredException extends AdmissionsException
{
    public function __construct(public readonly int $candidateCount)
    {
        parent::__construct(
            422,
            'ADMISSION_GUARDIAN_SELECTION_REQUIRED',
            'One or more existing Guardians already match this contact value -- choose an existing Guardian to link instead of creating a new one.',
        );
    }
}
