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
        ];
    }
}
