<?php

namespace App\Domain\Platform\Application\Modules;

/**
 * ORG.5 (ADR 0076 §12.7(3), §31): a queued job or outbox consumer that
 * performs an OPTIONAL module's business effect without an interactive
 * capability check. It declares its module, and its execution point asks
 * ModuleExecutionGuard -- at the moment it runs, never from state captured
 * when it was queued -- and ends in the substrate's own terminal outcome
 * when the module is no longer entitled (never a retry loop).
 * AsyncModuleContractArchitectureTest pins every queued job and consumer
 * to either this contract or an explicit core/infrastructure exemption.
 */
interface ExecutesModuleWork
{
    /** The optional module whose work this performs (a ModuleCatalogue key). */
    public static function executesModule(): string;
}
