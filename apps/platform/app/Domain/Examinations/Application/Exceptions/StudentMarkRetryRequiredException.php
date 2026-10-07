<?php

namespace App\Domain\Examinations\Application\Exceptions;

/**
 * S5 (ADR 0068 §27.11; ADR 0038 lock-order amendment): PostgreSQL aborted the
 * StudentMark transaction as a deadlock victim or serialization failure.
 * Nothing was saved -- no mark, revision, correction or audit row -- and the
 * whole request may simply be sent again; every check (P3, ownership,
 * processing basis, paper, year, version) re-runs. Fixed text: never an
 * SQLSTATE, SQL, table, lock or value.
 */
class StudentMarkRetryRequiredException extends ExaminationException
{
    public function __construct()
    {
        parent::__construct(409, 'STUDENT_MARK_RETRY_REQUIRED', 'A concurrent change interrupted this request; nothing was saved. Please retry.');
    }
}
