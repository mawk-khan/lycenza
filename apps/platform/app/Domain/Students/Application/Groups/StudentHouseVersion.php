<?php

namespace App\Domain\Students\Application\Groups;

use App\Domain\Students\Infrastructure\StudentGroupMembership;

/**
 * SIS 11.7: the optimistic-concurrency token of a Student's House
 * membership -- a digest of the CURRENT membership (or "none"), the same
 * discipline as SIS 11.H1's version tokens. Two operators who opened the
 * same Student see the same token; whoever saves first changes it, and the
 * other's stale submission is refused (StudentHouseChangedException).
 */
final class StudentHouseVersion
{
    public static function of(string $studentId, ?StudentGroupMembership $current): string
    {
        return hash('sha256', implode("\x1f", [
            'student-house:v1',
            $studentId,
            $current === null ? 'none' : $current->id,
            $current === null ? '' : $current->student_group_id,
            $current === null ? '' : (string) $current->version,
        ]));
    }
}
