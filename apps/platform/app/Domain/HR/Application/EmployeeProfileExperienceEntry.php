<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.9 -- one `EmployeeExperience` row projected into the
 * Profile Workspace's Restricted "Professional Experience" section.
 * Deliberately structurally distinct from
 * `EmployeeProfileEmploymentEntry` -- external professional history is
 * never combined with this School's own EmploymentRecord history into
 * one timeline. Overlapping entries are legitimate and preserved.
 * Ordered `starts_on DESC, id`.
 */
final class EmployeeProfileExperienceEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $organization,
        public readonly string $jobTitle,
        public readonly string $startsOn,
        public readonly ?string $endsOn,
        public readonly ?string $description,
        public readonly ?string $location,
        public readonly ?string $countryCode,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organization' => $this->organization,
            'job_title' => $this->jobTitle,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn,
            'description' => $this->description,
            'location' => $this->location,
            'country_code' => $this->countryCode,
        ];
    }
}
