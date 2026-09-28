<?php

namespace App\Support\Email;

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantRls;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

/**
 * Phase 0O.10A (ADR 0056 section 9.3): the explicit scope in which
 * IDENTITY-level email rows (account recovery, security notices -- no
 * School) are visible, through the database policy
 * (TenantRls::enableWithPlatformScope) and SchoolScope alike.
 *
 * Mutually exclusive with a School TenantContext: it refuses to start
 * inside one, and TenantContext::set() refuses to run inside it. It ends
 * (RESET) when the outermost run() returns or throws, like TenantContext's
 * withSchool(). Request/job-scoped (a `scoped` binding), so a long-running
 * worker never carries it into the next unit of work (rule 57).
 */
final class PlatformEmailScope
{
    private int $depth = 0;

    public function __construct(private readonly TenantContext $context) {}

    public function active(): bool
    {
        return $this->depth > 0;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        if ($this->context->hasSchool()) {
            throw new LogicException('The platform email scope never runs inside a School context.');
        }

        if ($this->depth++ === 0) {
            DB::select('SELECT set_config(?, ?, false)', [TenantRls::PLATFORM_EMAIL_SCOPE_VAR, 'on']);
        }

        try {
            $result = $callback();
        } catch (Throwable $e) {
            if (--$this->depth === 0) {
                $this->resetAfterFailure();
            }

            throw $e;
        }

        if (--$this->depth === 0) {
            DB::statement('RESET '.TenantRls::PLATFORM_EMAIL_SCOPE_VAR);
        }

        return $result;
    }

    /**
     * The same split as TenantContext::withSchool(): when the callback's own
     * database failure aborted the surrounding transaction, the RESET fails
     * with SQLSTATE 25P02 -- and is unnecessary, because the rollback of
     * that transaction (by whoever owns it) reverts the session value. Only
     * that one secondary failure is swallowed, so it never masks the real
     * error; any other failure still surfaces.
     */
    private function resetAfterFailure(): void
    {
        try {
            DB::statement('RESET '.TenantRls::PLATFORM_EMAIL_SCOPE_VAR);
        } catch (QueryException $e) {
            if ($e->getCode() !== '25P02') {
                throw $e;
            }
        }
    }
}
