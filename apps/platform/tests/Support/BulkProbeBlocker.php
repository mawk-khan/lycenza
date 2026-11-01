<?php

namespace Tests\Support;

use App\Domain\Students\Application\StudentTransferBlocker;
use App\Models\School;

/** SIS 11.13, test-only: a StudentTransferBlocker for one Student (BulkStudentOperationsTest proves bulk asks the registered set). */
final class BulkProbeBlocker implements StudentTransferBlocker
{
    public function __construct(private readonly string $studentId) {}

    public function blocksCampusTransfer(School $school, string $studentId, string $sourceEnrollmentId, string $sourceCampusId): bool
    {
        return $studentId === $this->studentId;
    }

    public function kind(): string
    {
        return 'the bulk probe record';
    }
}
