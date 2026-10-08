<?php

namespace App\Support\Portal;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * POR (ADR 0070 §18.2): the portal is refused in this environment. One fixed
 * 403 message; it names no School, Guardian, Student or environment.
 */
final class PortalUnavailableException extends AccessDeniedHttpException
{
    public const CODE = 'PORTAL_UNAVAILABLE';

    public const MESSAGE = 'The Guardian portal is not available in this environment.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
