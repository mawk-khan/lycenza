<?php

namespace App\Domain\HR\Application\Credentials;

use App\Domain\HR\Application\Exceptions\CredentialRevisionChangedException;
use App\Domain\HR\Infrastructure\EmployeeCertification;
use App\Domain\HR\Infrastructure\EmployeeCredentialVerification;
use App\Domain\HR\Infrastructure\EmployeeQualification;
use App\Models\User;

/**
 * HR.R1-C29 (C14-N5, owner decisions C29-A/B, 2026-11-12): the one writer of `employee_credential_verifications`.
 * A Verify or Reject names the revision its decision-maker saw, and records one decision on that revision.
 *
 * The caller holds the credential row FOR UPDATE in its transaction, so the revision compared here is the one the
 * decision commits on: a revision other than the current one is refused (CredentialRevisionChangedException) and
 * nothing is written. The same decision repeated on a revision that already carries it is no new evidence and
 * writes nothing (a double click, a resubmitted form). The insert sets the credential's projection in the
 * database; this class never writes the credential row.
 */
final class CredentialVerificationDecisions
{
    /**
     * @return EmployeeCredentialVerification|null the recorded decision, or null when this revision already carries it
     */
    public function decide(EmployeeQualification|EmployeeCertification $locked, int $revision, string $decision, User $actor): ?EmployeeCredentialVerification
    {
        if ((int) $locked->revision !== $revision) {
            throw new CredentialRevisionChangedException;
        }
        if ($locked->verification_status === $decision && $locked->verification_revision !== null && (int) $locked->verification_revision === $revision) {
            return null;
        }

        $kind = $locked instanceof EmployeeQualification ? EmployeeCredentialVerification::QUALIFICATION : EmployeeCredentialVerification::CERTIFICATION;

        return EmployeeCredentialVerification::query()->forceCreate([
            'school_id' => $locked->school_id,
            'employee_id' => $locked->employee_id,
            'credential_kind' => $kind,
            'qualification_id' => $kind === EmployeeCredentialVerification::QUALIFICATION ? $locked->id : null,
            'certification_id' => $kind === EmployeeCredentialVerification::CERTIFICATION ? $locked->id : null,
            'revision' => $revision,
            'decision' => $decision,
            'decided_by_user_id' => $actor->id,
            // The database sets the time (employee_credential_verifications_guard); this only satisfies NOT NULL.
            'decided_at' => now(),
        ]);
    }
}
