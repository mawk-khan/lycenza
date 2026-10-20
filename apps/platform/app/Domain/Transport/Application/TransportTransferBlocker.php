<?php

namespace App\Domain\Transport\Application;

use App\Domain\Students\Application\StudentTransferBlocker;
use App\Domain\Transport\Infrastructure\TransportStudentAssignment;
use App\Models\School;

/**
 * ORG.7F-3 (ADR 0076 §47, owner decision F3-D6): an ACTIVE rider assignment on
 * a route of the SOURCE Campus blocks a Campus-scoped transfer; Transport ends
 * or changes it first. Never ended here. Ended assignments (history) and
 * School-wide (NULL-Campus) routes do not block.
 *
 * Serialization: every rider assignment locks the Student FOR NO KEY UPDATE
 * first (TransportStudentAssignmentService::STUDENT_LOCK); the transfer holds
 * the Student FOR SHARE, so an assignment committed first is seen and one
 * arriving later waits, then (Campus-scoped) finds the Student no longer on the
 * source Campus.
 */
final class TransportTransferBlocker implements StudentTransferBlocker
{
    public function blocksCampusTransfer(School $school, string $studentId, string $sourceEnrollmentId, string $sourceCampusId): bool
    {
        return TransportStudentAssignment::query()
            ->where('transport_student_assignments.school_id', $school->id)
            ->where('transport_student_assignments.student_id', $studentId)
            ->where('transport_student_assignments.status', 'active')
            ->whereHas('route', fn ($q) => $q->where('transport_routes.campus_id', $sourceCampusId))
            ->exists();
    }
}
