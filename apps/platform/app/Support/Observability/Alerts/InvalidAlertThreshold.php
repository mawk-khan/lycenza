<?php

namespace App\Support\Observability\Alerts;

use InvalidArgumentException;

/** An operator alert value outside its safeguard bounds; the message names the key, never a value. */
final class InvalidAlertThreshold extends InvalidArgumentException {}
