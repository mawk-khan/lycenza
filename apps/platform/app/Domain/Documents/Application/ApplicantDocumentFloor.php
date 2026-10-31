<?php

namespace App\Domain\Documents\Application;

use App\Models\School;
use App\Models\User;
use App\Support\Authorization\Campus\CampusScopeResolver;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * SIS 11.H1 (SIS 11.R1 L-2): the capability floor of the Applicant
 * (pre-admission) Documents entry points, decided by Documents itself.
 *
 * `admissions.documents.*` is Campus-adopted with the application, and only
 * Admissions knows an application's Campus. So the authority is split:
 * - here, in Documents: the actor must hold the capability in SOME scope
 *   of this School (whole School or at least one Campus), else the call is
 *   refused -- a future caller that forgets authorization is stopped;
 * - in Admissions (ApplicantDocumentService): the application must lie in
 *   the actor's Campus scope (out of scope = NOT FOUND), before any of
 *   these entry points is reached.
 * Neither check replaces the other.
 */
final class ApplicantDocumentFloor
{
    public function __construct(private readonly CampusScopeResolver $scopes) {}

    public function require(User $actor, string $capability, School $school): void
    {
        if ($this->scopes->campusScope($actor, $capability, $school)->isNone()) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }
}
