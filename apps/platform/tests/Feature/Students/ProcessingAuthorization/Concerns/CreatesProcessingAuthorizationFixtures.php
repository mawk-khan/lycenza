<?php

namespace Tests\Feature\Students\ProcessingAuthorization\Concerns;

use App\Models\School;
use App\Models\User;
use Tests\Concerns\CreatesTenancyFixtures;

/**
 * Phase 0H.4D-P2 test-only helper: a staff User with a real
 * SchoolMembership and School-scoped role assignment, exactly the
 * shape production authorization actually checks (CreatesTenancyFixtures'
 * own "no test-only bypass" discipline).
 */
trait CreatesProcessingAuthorizationFixtures
{
    use CreatesTenancyFixtures;

    protected function staffActor(School $school, string $roleKey = 'school_admin'): User
    {
        $user = $this->createUser();
        $membership = $this->createMembership($user, $school);
        $this->assignSchoolRole($membership, $roleKey);

        return $user;
    }
}
