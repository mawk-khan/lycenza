<?php

namespace App\Domain\Platform\Application\Groups\Reporting;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * A Group report that failed after authorization because a source read
 * failed (ADR 0048 section 11, case D): the whole report is refused -- no
 * partial aggregate, no stale substitute. Already audited as
 * `platform.school_group_report.failed` (`source_error`).
 */
class GroupReportFailedException extends HttpException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(503, 'The Group report is unavailable right now.', $previous);
    }
}
