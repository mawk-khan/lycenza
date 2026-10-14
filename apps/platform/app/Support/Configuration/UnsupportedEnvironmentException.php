<?php

namespace App\Support\Configuration;

use RuntimeException;

/**
 * PPR.1 (ADR 0074 section 26.2): APP_ENV holds a value outside
 * EnvironmentPolicy::SUPPORTED. Thrown from AppServiceProvider::register(),
 * before anything can serve a request or run a command, with debug forced
 * off. The value itself is never echoed (a secret pasted into the wrong
 * variable must not reach a log or a terminal); the message says whether it
 * was empty, its length and the supported set -- enough for an operator to
 * find the mistake.
 */
final class UnsupportedEnvironmentException extends RuntimeException
{
    public function __construct(string $environment)
    {
        parent::__construct(sprintf(
            'Refusing to boot: APP_ENV is not a supported environment (%s). Supported values: %s.',
            $environment === '' ? 'it is empty' : 'a '.mb_strlen($environment).'-character value',
            implode(', ', EnvironmentPolicy::SUPPORTED),
        ));
    }
}
