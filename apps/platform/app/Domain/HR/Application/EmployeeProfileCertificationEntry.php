<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.9 -- one `EmployeeCertification` row projected into the
 * Profile Workspace's Restricted "Certifications" section.
 * `credentialNumber` is included here (the Restricted HR-internal
 * view, not Directory) but remains Restricted-tier information --
 * never promoted to Directory tier. No certificate file content is
 * ever included; document evidence lives entirely in the separate
 * `EmployeeDocument` section. Verifier identity is not exposed, same
 * reasoning as `EmployeeProfileQualificationEntry`. Ordered
 * `issued_on DESC, id`.
 */
final class EmployeeProfileCertificationEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $issuer,
        public readonly ?string $credentialNumber,
        public readonly ?string $issuedOn,
        public readonly ?string $expiresOn,
        public readonly string $verificationStatus,
        public readonly ?string $verifiedAt,
        // HR.R1-C29 (C14-N5): the current revision, the revision the status names (null with a status = before
        // version recording) and every recorded decision, newest first, without who decided.
        public readonly int $revision = 1,
        public readonly ?int $verificationRevision = null,
        /** @var list<array{decision: string, revision: int, decided_at: string}> */
        public readonly array $verificationHistory = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'issuer' => $this->issuer,
            'credential_number' => $this->credentialNumber,
            'issued_on' => $this->issuedOn,
            'expires_on' => $this->expiresOn,
            'verification_status' => $this->verificationStatus,
            'verified_at' => $this->verifiedAt,
            'revision' => $this->revision,
            'verification_revision' => $this->verificationRevision,
            'verification_history' => $this->verificationHistory,
        ];
    }
}
