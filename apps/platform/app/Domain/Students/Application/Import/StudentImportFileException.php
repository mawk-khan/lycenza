<?php

namespace App\Domain\Students\Application\Import;

use RuntimeException;

/** SIS.EXP-5: the file itself cannot be read as a v1 import (shown on the file field; nothing was written). */
final class StudentImportFileException extends RuntimeException {}
