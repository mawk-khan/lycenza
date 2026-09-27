<?php

namespace App\Support\Auth;

use RuntimeException;

/** The handoff store cannot be reached: no ticket is issued or redeemed (fail closed). */
final class HandoffUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The sign-in handoff store is unavailable.');
    }
}
