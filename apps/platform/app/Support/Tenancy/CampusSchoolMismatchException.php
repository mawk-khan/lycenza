<?php

namespace App\Support\Tenancy;

use RuntimeException;

/**
 * Thrown when a Campus belonging to one School is used while a
 * different School is active. Campus is not itself a tenant boundary
 * (ADR 0004), but it must never be usable outside its own School.
 */
class CampusSchoolMismatchException extends RuntimeException
{
    public function __construct(string $campusId, string $expectedSchoolId, string $actualSchoolId)
    {
        parent::__construct(
            "Campus {$campusId} belongs to School {$actualSchoolId}, ".
            "not the active School {$expectedSchoolId}."
        );
    }
}
