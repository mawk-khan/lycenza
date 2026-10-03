<?php

namespace App\Domain\Leave\Application;

/**
 * HRX.1 (ADR 0065 §12): the Leave capabilities this checkpoint uses. Never a
 * role-name check: every service authorizes one of these (route middleware
 * is the outer layer). HRX.2+ add `hr.leave.approve` and `hr.leave.self`.
 */
final class LeaveCapabilities
{
    /** Leave types, policies, leave-year settings, the staff working calendar. */
    public const CONFIGURE = 'hr.leave.configure';

    /** Read every Leave record of the School. */
    public const VIEW = 'hr.leave.view';

    /** Policy assignments, allocations, allocation runs, adjustments. */
    public const MANAGE = 'hr.leave.manage';
}
