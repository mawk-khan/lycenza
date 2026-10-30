<?php

namespace App\Domain\Students\Application\Exceptions;

/**
 * SIS.EXP-4: the Student has no canonical qualifying exit for this
 * certificate type (StudentCertificateEligibility). The code is closed;
 * nothing was written.
 */
class StudentCertificateNotEligibleException extends StudentException
{
    public const array MESSAGES = [
        'still_placed' => 'This Student still has an active placement. A certificate can be issued only after the Student has left.',
        'no_placement' => 'This Student has never been placed, so there is no exit to certify.',
        'exit_not_recorded' => 'This Student\'s last placement ended, but no exit (withdrawal or marking the Student inactive) has been recorded. Record the exit first.',
        'not_an_exit' => 'This Student was marked inactive for a reason that is not an exit (a duplicate or an error), so no certificate can be issued.',
        'returned' => 'This Student was marked active again after leaving, so they have returned and there is no exit to certify.',
        'other_exit' => 'This certificate belongs to an earlier exit, so it cannot be replaced by a certificate of the current exit. Void it instead.',
        'not_transferred_out' => 'A Transfer Certificate needs the exit reason "Transferred to another School". Issue a Leaving Certificate instead.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(409, 'STUDENT_CERTIFICATE_NOT_ELIGIBLE', self::MESSAGES[$reason] ?? 'This Student is not eligible for this certificate.');
    }
}
