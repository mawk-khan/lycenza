<?php

namespace App\Domain\Identity\Application\Staff;

use RuntimeException;

/**
 * Phase 0O.12B (ADR 0059): a refused staff-account or bootstrap-account
 * operation. `outcome` is a closed, bounded code (safe for audit, logs and
 * metrics); the message is written for the person in front of the screen
 * and never names another School, a platform role or an account state the
 * caller may not learn (ADR 0059 section 15).
 */
final class StaffAccountException extends RuntimeException
{
    public const MESSAGES = [
        'not_authorized' => 'You cannot manage staff accounts in this School.',
        'school_not_operational' => 'This School is not active, so its staff accounts cannot be changed.',
        'email_unavailable' => 'Invitations cannot be sent yet: this deployment has no working email delivery.',
        'rate_limited' => 'Too many invitations were sent recently. Try again later.',
        'invalid_email' => 'Enter a valid email address.',
        'roles_required' => 'Choose at least one School role.',
        'role_unknown' => 'Choose roles from the list.',
        'role_escalation' => 'You can only grant roles whose permissions you hold yourself.',
        'already_member' => 'That person is already a member of this School.',
        'already_invited' => 'That address already has a pending invitation. Resend or revoke it instead.',
        'invitation_not_pending' => 'That invitation is no longer pending.',
        'not_found' => 'That staff account was not found in this School.',
        'not_staff' => 'That account is not a staff account in this School.',
        'self_administration' => 'You cannot change your own access here. Ask another School administrator.',
        'not_active' => 'That staff account is not active.',
        'not_suspended' => 'That staff account is not suspended.',
        'account_unavailable' => 'That account cannot be reactivated here.',
        'role_already_granted' => 'That role is already granted.',
        'role_not_granted' => 'That role is not currently granted.',
        'last_administrator' => 'This would leave the School without an administrator who can manage staff. Add or keep another administrator first.',
    ];

    public function __construct(public readonly string $outcome)
    {
        parent::__construct(self::MESSAGES[$outcome] ?? 'This action is not possible.');
    }
}
