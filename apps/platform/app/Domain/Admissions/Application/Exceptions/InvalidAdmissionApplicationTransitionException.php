<?php

namespace App\Domain\Admissions\Application\Exceptions;

/**
 * Phase 1D.2: the ONLY status values reachable through
 * AdmissionApplicationService are `draft -> submitted`,
 * `submitted -> accepted/rejected/withdrawn`, and
 * `accepted -> withdrawn` (docs/modules/ADMISSIONS.md §6). Every
 * terminal status (`rejected`/`withdrawn`) and `converted` (entered
 * only by a future 1D.3 conversion command, never by this service) is
 * final -- none of them may transition again through this service.
 * Mirrors
 * App\Domain\Students\Application\Exceptions\InvalidEnrollmentTransitionException's
 * identical shape.
 */
class InvalidAdmissionApplicationTransitionException extends AdmissionsException
{
    /** SIS 3.2: the operator's words for each status (never a raw status key). */
    private const array NOW = [
        'draft' => 'is still a draft',
        'submitted' => 'has been submitted',
        'accepted' => 'has been accepted',
        'rejected' => 'has been rejected',
        'withdrawn' => 'has been withdrawn',
        'converted' => 'has already been converted to a Student',
    ];

    private const array ACTION = [
        'submitted' => 'submitted',
        'accepted' => 'accepted',
        'rejected' => 'rejected',
        'withdrawn' => 'withdrawn',
        'converted' => 'converted to a Student',
    ];

    public function __construct(public readonly string $from, public readonly string $to)
    {
        $now = self::NOW[$from] ?? 'has changed';
        $action = self::ACTION[$to] ?? 'changed this way';

        parent::__construct(
            422,
            'INVALID_ADMISSION_APPLICATION_TRANSITION',
            "This application {$now}, so it can't be {$action}. Refresh the page to see what can be done next.",
        );
    }
}
