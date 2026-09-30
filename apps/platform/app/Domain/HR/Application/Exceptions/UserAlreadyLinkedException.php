<?php

namespace App\Domain\HR\Application\Exceptions;

/**
 * TCH.1: translated from `employees_school_id_user_id_unique` -- the
 * database, not a pre-check, is what guarantees one User is linked to at
 * most one Employee per School (two concurrent links of the same User
 * serialize on the unique index; the second fails here). A User may still
 * be an Employee at several different Schools.
 */
class UserAlreadyLinkedException extends HrException
{
    public const string CONSTRAINT = 'employees_school_id_user_id_unique';

    public function __construct(public readonly string $userId)
    {
        parent::__construct(409, 'HR_USER_ALREADY_LINKED', 'The specified User is already linked to another Employee in this School.');
    }
}
