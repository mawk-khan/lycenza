<?php

namespace App\Domain\Leave\Application;

/**
 * HRX.1 (ADR 0065 §12): the Leave capabilities this checkpoint uses. Never a
 * role-name check: every service authorizes one of these (route middleware
 * is the outer layer). HRX.2 adds `hr.leave.approve`; HRX.4 adds `hr.leave.self`.
 */
final class LeaveCapabilities
{
    /** Leave types, policies, leave-year settings, the staff working calendar. */
    public const CONFIGURE = 'hr.leave.configure';

    /** Read every Leave record of the School. */
    public const VIEW = 'hr.leave.view';

    /**
     * Policy assignments, allocations, allocation runs, adjustments; HRX.2:
     * requests on behalf, administrative decisions, cancellation, year close.
     */
    public const MANAGE = 'hr.leave.manage';

    /** HRX.2: decide a DIRECT REPORT's request -- always together with fresh reporting ownership (ADR 0065 §23.6). */
    public const APPROVE = 'hr.leave.approve';

    /**
     * HRX.4: the acting Employee's OWN leave -- balances, requests, submit,
     * withdraw, cancel before start -- always together with ActingEmployee
     * ownership (ADR 0065 §25.4). Never School-wide.
     */
    public const SELF = 'hr.leave.self';
}
