<?php

namespace App\Support\Ai;

use RuntimeException;

/**
 * Thrown when Laravel code attempts to dispatch an AI tool call for an
 * actor who does not actually hold the required capability in the
 * target School. This check happens BEFORE any context token is
 * minted -- see AiGatewayClient::invokeTool() -- so an actor can never
 * cause a School-B-scoped context token to be issued using only their
 * School A access.
 */
class AiGatewayAuthorizationException extends RuntimeException {}
