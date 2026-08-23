<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.9 -- one `EmployeeEmergencyContact` row projected into the
 * Profile Workspace's Restricted "Emergency Contacts" section. These
 * remain external-person records, never Employee/User identities.
 * Ordered primary-first, then `name`, then `id`.
 */
final class EmployeeProfileEmergencyContactEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $relationship,
        public readonly string $phone,
        public readonly ?string $alternatePhone,
        public readonly ?string $email,
        public readonly bool $isPrimary,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'relationship' => $this->relationship,
            'phone' => $this->phone,
            'alternate_phone' => $this->alternatePhone,
            'email' => $this->email,
            'is_primary' => $this->isPrimary,
        ];
    }
}
