<?php

namespace App\Jobs;

use App\Domain\Platform\Application\Domains\SchoolDomainCheckService;
use App\Models\SchoolDomain;
use App\Support\Tenancy\TenantScoped;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 0O.8A (ADR 0054 section 10.4): a School administrator's rate-limited
 * "check now". The DNS and TLS work (bounded: <= 10 s per DNS check, <= 2
 * probe addresses at 5 s + 5 s) runs here, never inside the web request.
 * SchoolDomainCheckService re-checks the School's lifecycle at execution
 * time (SchoolOperationalGuard) and only acts on the row it was asked about.
 * One try: a later scheduled or manual check simply runs again.
 */
class CheckSchoolDomainJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TenantScoped;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $domainId)
    {
        $this->captureTenantContext();
    }

    public function handle(SchoolDomainCheckService $checks): void
    {
        $domain = SchoolDomain::query()->where('school_id', $this->contextSchoolId)->find($this->domainId);

        if ($domain !== null) {
            $checks->check($domain);
        }
    }
}
