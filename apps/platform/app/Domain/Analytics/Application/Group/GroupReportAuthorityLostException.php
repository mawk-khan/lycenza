<?php

namespace App\Domain\Analytics\Application\Group;

use RuntimeException;

/**
 * The actor no longer holds `group.reporting.view` in the named Group when
 * a School was about to be read: the Group report must fail closed.
 */
class GroupReportAuthorityLostException extends RuntimeException {}
