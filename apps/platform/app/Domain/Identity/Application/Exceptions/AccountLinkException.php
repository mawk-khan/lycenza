<?php

namespace App\Domain\Identity\Application\Exceptions;

use RuntimeException;

/**
 * Base exception for App\Domain\Identity\Application\AccountLinkService
 * failures -- the same "one base type per module, catchable generically
 * by a controller" shape App\Domain\Communications\Application\Exceptions\CommunicationException
 * already established.
 */
abstract class AccountLinkException extends RuntimeException {}
