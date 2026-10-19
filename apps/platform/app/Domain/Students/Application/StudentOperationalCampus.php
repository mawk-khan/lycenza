<?php

namespace App\Domain\Students\Application;

/**
 * ORG.7D-2 (ADR 0076 §38): a Student's CURRENT operational Campus context,
 * as StudentOperationalCampusResolver found it. Data, never authority: a
 * caller still authorizes its own capability (and CampusScope) first.
 *
 * Two facts are kept apart on purpose:
 * - hasCurrentPlacement(): an `active` Enrollment exists in the School's
 *   `active` AcademicYear (StudentEnrollmentReadService's definition);
 * - operationalCampusId(): that placement's Campus, but only while the
 *   Student AND the Campus are active -- otherwise null.
 *
 * So a placement on a Campus that was deactivated, or of a Student who has
 * left, stays visible as context without ever making the Campus eligible for
 * Campus-scoped work.
 */
final class StudentOperationalCampus
{
    public function __construct(
        public readonly string $studentId,
        public readonly bool $studentActive,
        public readonly ?string $currentEnrollmentId,
        public readonly ?string $placementCampusId,
        public readonly bool $campusActive,
    ) {}

    public static function none(string $studentId): self
    {
        return new self($studentId, false, null, null, false);
    }

    public function hasCurrentPlacement(): bool
    {
        return $this->currentEnrollmentId !== null;
    }

    /** The Campus that makes the Student a current operational candidate, or null. */
    public function operationalCampusId(): ?string
    {
        return $this->studentActive && $this->hasCurrentPlacement() && $this->campusActive ? $this->placementCampusId : null;
    }
}
