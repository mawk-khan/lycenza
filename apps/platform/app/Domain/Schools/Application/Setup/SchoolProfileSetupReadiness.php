<?php

namespace App\Domain\Schools\Application\Setup;

use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;

/**
 * ORG.12A (ADR 0080 §10): School profile readiness from the School's own
 * profile fields. Reports only which kinds of field are filled, never a
 * value.
 */
class SchoolProfileSetupReadiness implements SetupReadinessProvider
{
    public function keys(): array
    {
        return ['organization.school_profile'];
    }

    public function evaluate(string $key, School $school): SetupReadiness
    {
        $profile = School::query()->whereKey($school->id)
            ->firstOrFail(['id', 'legal_name', 'email', 'phone', 'address_line1', 'city', 'country_code']);

        return SetupReadiness::fromChecks(
            [
                'legal_name' => filled($profile->legal_name),
                'contact' => filled($profile->email) || filled($profile->phone),
                'address' => filled($profile->address_line1) && filled($profile->city) && filled($profile->country_code),
            ],
            configured: 'The School\'s legal name, contact details and address are recorded.',
            incomplete: 'Add the School\'s legal name, a contact email or phone, and its address.',
        );
    }
}
