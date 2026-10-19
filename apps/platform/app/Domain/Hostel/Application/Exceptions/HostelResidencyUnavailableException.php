<?php

namespace App\Domain\Hostel\Application\Exceptions;

/**
 * ORG.7D-3 (ADR 0076 §39, D1-12 standard): what a Campus-scoped Hostel actor
 * sees when a residency cannot be created for a Student -- the Student is not
 * (or no longer) a current operational Student of the Hostel's Campus, or
 * already holds a residency somewhere in the School -- so the answer never
 * says where or why. Whole-School actors keep the specific messages.
 */
class HostelResidencyUnavailableException extends HostelException
{
    public function __construct()
    {
        parent::__construct(422, 'HOSTEL_RESIDENCY_UNAVAILABLE', 'This Student cannot be given a residency here right now.');
    }
}
