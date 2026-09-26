<?php

namespace App\Support\Observability\Metrics;

use RuntimeException;

/** The deployment evidence file is unusable; `getMessage()` is a fixed code, never file content. */
final class InvalidDeploymentEvidence extends RuntimeException {}
