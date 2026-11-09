<?php

namespace App\Domain\Leave\Application\Calendar;

/**
 * HR.R1-C22 (WO-5, D6-5): the closed reasons for a past-dated staff calendar change. `cutover` is the
 * migration's own and is never accepted from a caller (database-enforced).
 */
enum CalendarChangeReason: string
{
    case LateDeclaration = 'late_declaration';
    case EnteredInError = 'entered_in_error';
    case AdministrativeReview = 'administrative_review';
}
