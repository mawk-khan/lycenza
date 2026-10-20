<?php

namespace App\Domain\Students\Application;

use App\Models\School;

/**
 * ORG.7F-3 (ADR 0076 §47, owner decisions F3-D6, F3-D12): an ACTIVE
 * relationship of the Student on the SOURCE Campus that a Campus-scoped
 * transfer must not leave behind -- and must never resolve itself.
 *
 * Students defines the contract and asks every blocker inside the transfer
 * transaction, after the Student (FOR SHARE), the Sections and the source
 * Enrollment (FOR UPDATE) are locked; the implementations live in the modules
 * that depend on Students (Hostel, Transport) or in Students itself
 * (electives), so Students never reads their tables (CLAUDE.md rule 4).
 * Registered under the container tag `TAG`.
 *
 * A blocker only answers; it never ends, moves or rewrites anything, and its
 * answer is never shown to a Campus actor beyond the one generic refusal.
 */
interface StudentTransferBlocker
{
    public const TAG = 'students.campus_transfer_blockers';

    /** Runs inside the transfer transaction, under the School's TenantContext. */
    public function blocksCampusTransfer(School $school, string $studentId, string $sourceEnrollmentId, string $sourceCampusId): bool;
}
