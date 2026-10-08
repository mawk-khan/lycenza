<?php

namespace App\Domain\Identity\Application\Portal;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * POR (ADR 0070 §5.2): the signed-in User is not a Guardian of the current
 * School for portal purposes. One fixed message whatever the reason (no
 * membership, link, active persona or eligible relationship) -- it never
 * tells the caller which part failed.
 */
final class GuardianPortalAccessDeniedException extends AccessDeniedHttpException
{
    public const MESSAGE = 'The Guardian portal is not available for this account in this School.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
