<?php

namespace App\Domain\Admissions\Application;

use App\Domain\Guardians\Infrastructure\ContactType;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\RelationshipType;

/**
 * Explicit conversion-time Guardian instruction for
 * `AdmissionConversionService::convert()` -- deliberately a closed,
 * two-mode value object (`create`/`link_existing`), never inferred
 * from field presence (`docs/modules/ADMISSIONS.md` §9.2: "staff make
 * an explicit create-vs-link decision"). Omitting this instruction
 * entirely (passing `null` to `convert()`) is the third, "no Guardian"
 * mode -- Guardian participation is optional at conversion
 * (`ADMISSIONS.md` §11's "Guardian optionality").
 *
 * Guardian name/contact are carried here as transient, in-memory
 * command input only -- Admissions never persists them (`ADMISSIONS.md`
 * §9); they flow straight into the canonical
 * `App\Domain\Guardians\Application\GuardianService`/
 * `GuardianContactService` inside the same outer transaction.
 */
final class GuardianConversionInstruction
{
    private function __construct(
        public readonly string $mode,
        public readonly RelationshipType $relationshipType,
        public readonly ?string $firstName,
        public readonly ?string $middleName,
        public readonly ?string $lastName,
        public readonly ?ContactType $contactType,
        public readonly ?string $contactValue,
        public readonly ?Guardian $existingGuardian,
        public readonly bool $isLegalGuardian,
        public readonly bool $isEmergencyContact,
        public readonly bool $isAuthorizedPickup,
    ) {}

    /**
     * Create a brand-new Guardian. `$contactType`/`$contactValue` are
     * optional -- when both are supplied, `AdmissionConversionService`
     * runs the existing candidate-lookup safeguard before creating
     * anything (`ADMISSIONS.md` §9.2).
     */
    public static function create(
        string $firstName,
        ?string $middleName,
        ?string $lastName,
        RelationshipType $relationshipType,
        ?ContactType $contactType = null,
        ?string $contactValue = null,
        bool $isLegalGuardian = false,
        bool $isEmergencyContact = false,
        bool $isAuthorizedPickup = false,
    ): self {
        return new self(
            mode: 'create',
            relationshipType: $relationshipType,
            firstName: $firstName,
            middleName: $middleName,
            lastName: $lastName,
            contactType: $contactType,
            contactValue: $contactValue,
            existingGuardian: null,
            isLegalGuardian: $isLegalGuardian,
            isEmergencyContact: $isEmergencyContact,
            isAuthorizedPickup: $isAuthorizedPickup,
        );
    }

    /**
     * Link an already-existing, staff-selected canonical Guardian --
     * never inferred from a name/contact match. School ownership is
     * verified by the composed
     * `StudentGuardianRelationshipService::link()` call itself (its own
     * `CrossSchoolRelationshipException`), the same reuse-don't-
     * duplicate discipline root CLAUDE.md rule 13 (Phase 1D.2's
     * precedent) already established for Section compatibility.
     */
    public static function linkExisting(
        Guardian $existingGuardian,
        RelationshipType $relationshipType,
        bool $isLegalGuardian = false,
        bool $isEmergencyContact = false,
        bool $isAuthorizedPickup = false,
    ): self {
        return new self(
            mode: 'link_existing',
            relationshipType: $relationshipType,
            firstName: null,
            middleName: null,
            lastName: null,
            contactType: null,
            contactValue: null,
            existingGuardian: $existingGuardian,
            isLegalGuardian: $isLegalGuardian,
            isEmergencyContact: $isEmergencyContact,
            isAuthorizedPickup: $isAuthorizedPickup,
        );
    }

    public function hasContact(): bool
    {
        return $this->contactType !== null && $this->contactValue !== null;
    }
}
