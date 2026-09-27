<?php

namespace App\Support\ServiceAuth;

use RuntimeException;

/** A fully valid assertion from a service not authorized for this route (403). */
final class ServiceNotAuthorizedException extends RuntimeException
{
    public function __construct(public readonly string $service, public readonly string $kid)
    {
        parent::__construct('service_not_authorized');
    }
}
