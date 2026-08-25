<?php

namespace App\Support\Rollover;

/**
 * Phase 1B.7E/1B.7F: the ONE server-owned bound on how many Items a
 * single synchronous HTTP request to start()/resume() will process
 * before returning -- shared by the JSON API controller
 * (EnrollmentRolloverController) and the Inertia web controller
 * (App\Http\Controllers\App\EnrollmentRolloverController) so the limit
 * can never drift between the two surfaces. Never caller-controlled
 * (this checkpoint's brief, sections 33/34/92 of 1B.7E, 46/92 of
 * 1B.7F). A Plan with more pending Items than this simply returns with
 * the Plan still `executing`; the caller issues another Start/Resume
 * to continue.
 *
 * Reuses EnrollmentRolloverExecutionService's existing `$afterEachItem`
 * extension point (Phase 1B.7D's own deterministic-interruption test
 * seam) for this legitimate production purpose -- see that service's
 * own docblock. No orchestration logic is duplicated or redesigned
 * here.
 */
trait BoundsRolloverExecutionRequest
{
    private const MAX_ITEMS_PER_REQUEST = 100;

    private function stopAfterItemCap(): callable
    {
        $processed = 0;

        return function () use (&$processed) {
            $processed++;

            return $processed >= self::MAX_ITEMS_PER_REQUEST;
        };
    }
}
