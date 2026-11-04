<?php

namespace App\Domain\HR\Application\Separation;

/**
 * HR.12 (ADR 0071 HR.12 amendment): the separation keys. All whole-School,
 * never Campus-adopted (separation is terminal and School-wide, ORG.7E-1).
 */
final class SeparationCapabilities
{
    public const string VIEW = 'hr.separations.view';

    /** Record, withdraw, change the planned day, cancel, finalize, complete. */
    public const string MANAGE = 'hr.separations.manage';

    /** HR-061: approve or reject a recorded case (another person, fresh MFA to approve). */
    public const string APPROVE = 'hr.separations.approve';

    /** The School's administrative clearance requirements. */
    public const string CONFIGURE = 'hr.separations.configure';
}
