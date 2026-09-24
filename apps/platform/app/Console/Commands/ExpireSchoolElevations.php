<?php

namespace App\Console\Commands;

use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 0N.3 (ADR 0044 section 10): records `expired` (once, audited) for
 * every active platform elevation past its fixed expiry -- including one
 * whose session vanished without another request. Expiry itself never
 * depends on this: an overdue elevation is refused on its next request
 * whether or not the sweep has run. Idempotent; platform-owned rows only;
 * enters no School context.
 */
class ExpireSchoolElevations extends Command
{
    protected $signature = 'platform:expire-school-elevations';

    protected $description = 'Record expiry for platform School elevations past their fixed expiry.';

    public function handle(SchoolElevationService $elevations): int
    {
        $expired = $elevations->expireOverdue();

        $this->info("Expired {$expired} platform School elevation(s).");
        Log::info('platform.school_elevations_expire.completed', ['expired' => $expired]);

        return self::SUCCESS;
    }
}
