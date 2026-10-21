<?php

namespace App\Domain\Guardians\Application;

use App\Domain\Guardians\Application\Exceptions\GuardianSelfAdministrationException;
use App\Models\School;
use App\Models\User;

/**
 * ORG.8A-R1 (ADR 0076 §59), Rule B: an actor never links, unlinks or changes
 * a Student relationship of a Guardian identity that belongs to them
 * (`GuardianAccountOwnership`: any account link, active or revoked, in this
 * School). So no concession decider can remove their own relationship to
 * escape the beneficiary conflict and restore it afterwards. Structural ids
 * only; unrelated Guardians stay administrable exactly as before. A null
 * actor is a trusted internal path with no human actor (pinned by
 * GuardianSelfAdministrationTest: every application caller passes one).
 * The HrSelfAdministrationGuard precedent.
 */
final class GuardianSelfAdministrationGuard
{
    public function __construct(private readonly GuardianAccountOwnership $ownership) {}

    public function refuseOwnGuardian(?User $actor, School $school, string $guardianId): void
    {
        if ($actor !== null && in_array($guardianId, $this->ownership->guardianIdsOwnedBy($actor, $school), true)) {
            throw new GuardianSelfAdministrationException;
        }
    }
}
