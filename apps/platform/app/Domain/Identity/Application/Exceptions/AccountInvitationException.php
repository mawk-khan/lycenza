<?php

namespace App\Domain\Identity\Application\Exceptions;

use RuntimeException;

/**
 * Base exception for App\Domain\Identity\Application\AccountInvitationService
 * and App\Domain\Identity\Application\GuardianAccountActivationService
 * failures -- mirrors AccountLinkException's "one base type per
 * service family" shape.
 */
abstract class AccountInvitationException extends RuntimeException {}
