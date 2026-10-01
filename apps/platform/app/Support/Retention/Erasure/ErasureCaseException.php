<?php

namespace App\Support\Retention\Erasure;

use RuntimeException;

/** E21.2F: a refused erasure-case operation (the message names the closed reason). */
final class ErasureCaseException extends RuntimeException {}
