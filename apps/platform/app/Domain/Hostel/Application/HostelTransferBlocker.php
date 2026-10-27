<?php

namespace App\Domain\Hostel\Application;

use App\Domain\Hostel\Infrastructure\HostelResidencyAssignment;
use App\Domain\Students\Application\StudentTransferBlocker;
use App\Models\School;

/**
 * ORG.7F-3 (ADR 0076 §47, owner decision F3-D6): an ACTIVE residency in a
 * Hostel of the SOURCE Campus blocks a Campus-scoped transfer; the warden or a
 * whole-School Hostel workflow ends or moves it first. Never ended here. Ended
 * residencies (history) and residencies on another Campus do not block.
 *
 * Serialization: every residency assignment locks the Student FOR NO KEY
 * UPDATE first (HostelResidencyService::STUDENT_LOCK); the transfer holds the
 * Student FOR SHARE, so a residency committed first is seen and one arriving
 * later waits, then (Campus-scoped) finds the Student no longer on the source
 * Campus.
 */
final class HostelTransferBlocker implements StudentTransferBlocker
{
    public function blocksCampusTransfer(School $school, string $studentId, string $sourceEnrollmentId, string $sourceCampusId): bool
    {
        return HostelResidencyAssignment::query()
            ->where('hostel_residency_assignments.school_id', $school->id)
            ->where('hostel_residency_assignments.student_id', $studentId)
            ->where('hostel_residency_assignments.status', 'active')
            ->whereHas('bed.room.hostel', fn ($q) => $q->where('hostels.campus_id', $sourceCampusId))
            ->exists();
    }

    public function kind(): string
    {
        return 'the active Hostel residency on the current Campus (Hostel → Residency)';
    }
}
