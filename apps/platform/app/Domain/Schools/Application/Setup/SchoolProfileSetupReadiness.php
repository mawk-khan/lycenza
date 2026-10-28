<?php

namespace App\Domain\Schools\Application\Setup;

use App\Models\School;
use App\Support\Setup\SetupReadiness;
use App\Support\Setup\SetupReadinessProvider;

/**
 * ORG.12A (ADR 0080 §10): School profile readiness from the School's own
 * profile fields. Reports only which kinds of field are filled, never a
 * value.
 *
 * ORG.12C (ADR 0080 §8.9): the required minimum is an institutional
 * contact (email or phone) and the address fundamentals (first line, city,
 * country). The display name, timezone and Finance's currency always exist.
 * The legal name is optional (OD-12.1: it may simply equal the display
 * name), and so are the website, references, logo and coordinates -- none
 * of them makes a School incomplete.
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
            ->firstOrFail(['id', 'email', 'phone', 'address_line1', 'city', 'country_code']);

        return SetupReadiness::fromChecks(
            [
                'contact' => filled($profile->email) || filled($profile->phone),
                'address' => filled($profile->address_line1) && filled($profile->city) && filled($profile->country_code),
            ],
            configured: 'The School\'s contact details and address are recorded.',
            incomplete: 'Add a contact email or phone, and the School\'s address.',
        );
    }
}
