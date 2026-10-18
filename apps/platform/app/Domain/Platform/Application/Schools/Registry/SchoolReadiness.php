<?php

namespace App\Domain\Platform\Application\Schools\Registry;

use App\Models\School;
use App\Support\Authorization\SchoolAdministrators;

/**
 * ORG.3 (ADR 0076 §9.3, ORG-C4): a School's readiness, derived when read and
 * never stored. Advisory only: it never becomes an activation precondition
 * (ADR 0047 §5 invents no setup requirement) and never authorizes anything.
 *
 * Checks: a qualifying, activated administrator; at least one active
 * Campus; an active academic year; and, only where custom domains are
 * enabled and the School has claimed one, a domain that is not pending or
 * suspended. All pass = `ready`; otherwise `partially_configured` while the
 * School is `provisioning` and `needs_attention` after that.
 *
 * Warnings name their source condition and carry no tenant data.
 */
final class SchoolReadiness
{
    public const READY = 'ready';

    public const PARTIALLY_CONFIGURED = 'partially_configured';

    public const NEEDS_ATTENTION = 'needs_attention';

    /**
     * @param  array{active: int, inactive: int}  $campuses
     * @return array{state: string, checks: list<array{key: string, passed: bool}>, warnings: list<array{key: string, severity: string}>}
     */
    public static function evaluate(School $school, array $campuses, ?string $academicYear, string $administrator, string $domain, bool $domainsEnabled): array
    {
        $checks = [
            ['key' => 'administrator', 'passed' => $administrator === SchoolAdministrators::READY],
            ['key' => 'campus', 'passed' => $campuses['active'] > 0],
            ['key' => 'academic_year', 'passed' => $academicYear !== null],
        ];
        $domainClaimed = $domainsEnabled && in_array($domain, [SchoolRegistryReadService::DOMAIN_PENDING, SchoolRegistryReadService::DOMAIN_ATTENTION, SchoolRegistryReadService::DOMAIN_ACTIVE], true);
        if ($domainClaimed) {
            $checks[] = ['key' => 'custom_domain', 'passed' => $domain === SchoolRegistryReadService::DOMAIN_ACTIVE];
        }

        $warnings = [];
        if ($school->isProvisioning()) {
            $warnings[] = ['key' => 'awaiting_activation', 'severity' => 'info'];
        }
        if ($school->isClosed()) {
            $warnings[] = ['key' => 'school_closed', 'severity' => 'info'];
        } elseif ($school->isSuspended()) {
            $warnings[] = ['key' => 'school_suspended', 'severity' => 'warning'];
        }
        $warnings = [...$warnings, ...match ($administrator) {
            SchoolAdministrators::NOT_ACTIVATED => [['key' => 'administrator_not_activated', 'severity' => 'warning']],
            SchoolAdministrators::MISSING => [['key' => 'administrator_missing', 'severity' => 'warning']],
            default => [],
        }];
        if ($campuses['active'] === 0) {
            $warnings[] = ['key' => 'no_active_campus', 'severity' => 'warning'];
        }
        if ($academicYear === null) {
            $warnings[] = ['key' => 'no_active_academic_year', 'severity' => 'warning'];
        }
        if ($domainClaimed && $domain === SchoolRegistryReadService::DOMAIN_ATTENTION) {
            $warnings[] = ['key' => 'custom_domain_suspended', 'severity' => 'warning'];
        } elseif ($domainClaimed && $domain === SchoolRegistryReadService::DOMAIN_PENDING) {
            $warnings[] = ['key' => 'custom_domain_pending', 'severity' => 'info'];
        }

        $allPassed = ! in_array(false, array_column($checks, 'passed'), true);

        return [
            'state' => match (true) {
                $allPassed => self::READY,
                $school->isProvisioning() => self::PARTIALLY_CONFIGURED,
                default => self::NEEDS_ATTENTION,
            },
            'checks' => $checks,
            'warnings' => $warnings,
        ];
    }
}
