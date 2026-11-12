<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.9 -- one `EmployeeQualification` row projected into the
 * Profile Workspace's Restricted "Qualifications" section. Verifier
 * identity is deliberately not exposed -- 8A.6 intentionally uses
 * `AuditRecorder` actor identity rather than a `verified_by_user_id`
 * column, and this checkpoint does not synthesize one. Ordered
 * `starts_on DESC, id`.
 */
final class EmployeeProfileQualificationEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $qualificationType,
        public readonly string $qualificationName,
        public readonly ?string $specialization,
        public readonly string $institution,
        public readonly ?string $awardingBody,
        public readonly ?string $countryCode,
        public readonly ?string $startsOn,
        public readonly ?string $completedOn,
        public readonly ?string $gradeOrResult,
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
            'qualification_type' => $this->qualificationType,
            'qualification_name' => $this->qualificationName,
            'specialization' => $this->specialization,
            'institution' => $this->institution,
            'awarding_body' => $this->awardingBody,
            'country_code' => $this->countryCode,
            'starts_on' => $this->startsOn,
            'completed_on' => $this->completedOn,
            'grade_or_result' => $this->gradeOrResult,
            'verification_status' => $this->verificationStatus,
            'verified_at' => $this->verifiedAt,
            'revision' => $this->revision,
            'verification_revision' => $this->verificationRevision,
            'verification_history' => $this->verificationHistory,
        ];
    }
}
