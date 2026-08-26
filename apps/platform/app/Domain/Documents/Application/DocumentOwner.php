<?php

namespace App\Domain\Documents\Application;

/**
 * Phase 0E.2 -- the one typed owner descriptor at the Application
 * boundary, converted internally by DocumentService into exactly one
 * of the `documents` table's exclusive-arc columns
 * (employee_id/student_id/guardian_id). This is NOT a return to a
 * polymorphic database schema -- `$type` is closed to the three named
 * factory methods below (never an arbitrary caller-supplied string, a
 * model class name, or a Laravel morph-map key), so there is no owner
 * type this class can represent that the 0E.1 migration does not
 * already have a real column for.
 */
final class DocumentOwner
{
    private function __construct(
        public readonly string $type,
        public readonly string $id,
    ) {}

    public static function employee(string $employeeId): self
    {
        return new self('employee', $employeeId);
    }

    public static function student(string $studentId): self
    {
        return new self('student', $studentId);
    }

    public static function guardian(string $guardianId): self
    {
        return new self('guardian', $guardianId);
    }
}
