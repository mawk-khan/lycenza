<?php

namespace App\Support\Email\Providers;

use RuntimeException;

/** Testing only: FakeEmailProvider's "provider accepted, then the worker died" simulation. */
final class SimulatedWorkerCrash extends RuntimeException {}
