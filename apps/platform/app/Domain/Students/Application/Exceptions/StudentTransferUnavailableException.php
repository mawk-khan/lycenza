<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * ORG.7F-3 (ADR 0076 §47): a Campus-scoped transfer refused. One generic
 * answer for every refusal a Campus actor could probe on an in-scope Student
 * -- a destination out of scope, unknown, inactive, on the same Campus or in
 * another year; an unresolved Hostel, Transport or elective relationship; a
 * placement that stopped being current -- so it never discloses another
 * Campus, record, relationship or state.
 */
class StudentTransferUnavailableException extends StudentException
{
    public function __construct()
    {
        parent::__construct(422, 'STUDENT_TRANSFER_UNAVAILABLE', 'This Student cannot be transferred to that class now. Check the class, and that the Student has no unresolved Campus arrangements.');
    }
}
