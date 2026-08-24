<?php

namespace App\Domain\HR\Application;

/**
 * Phase 8A.9 -- one `EmployeeAddress` row projected into the Profile
 * Workspace's Restricted "Addresses" section. Ordered by
 * `address_type` then `id` (deterministic, never database natural row
 * order).
 */
final class EmployeeProfileAddressEntry
{
    public function __construct(
        public readonly string $id,
        public readonly string $addressType,
        public readonly string $addressLine1,
        public readonly ?string $addressLine2,
        public readonly ?string $city,
        public readonly ?string $stateRegion,
        public readonly ?string $postalCode,
        public readonly string $countryCode,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'address_type' => $this->addressType,
            'address_line1' => $this->addressLine1,
            'address_line2' => $this->addressLine2,
            'city' => $this->city,
            'state_region' => $this->stateRegion,
            'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode,
        ];
    }
}
