<?php

namespace App\Domain\HR\Application\AccountLinks;

/**
 * HR.R1-C7 (OD-HR-25): why a link is corrected as erroneous. A closed list: a link
 * that was true is never corrected, because an unlink does not change who a person
 * is. Matches `employee_account_links_void_check`.
 */
enum EmployeeAccountLinkVoidReason: string
{
    case WrongPerson = 'wrong_person';
    case SetupError = 'setup_error';

    public function label(): string
    {
        return match ($this) {
            self::WrongPerson => 'The account belonged to a different person',
            self::SetupError => 'It was created in error during setup',
        };
    }
}
