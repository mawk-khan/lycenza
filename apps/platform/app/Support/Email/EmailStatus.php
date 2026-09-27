<?php

namespace App\Support\Email;

use App\Support\Email\Events\EmailEventAdapterResolver;
use App\Support\Email\Providers\EmailProviderResolver;
use App\Support\Observability\ComponentStatus;
use App\Support\Observability\OperationalStatus;
use Illuminate\Contracts\Config\Repository;

/**
 * ADR 0055 section 16: the `email` Operations Status component. Email is an
 * optional subsystem for readiness purposes: at worst `Degraded`, never
 * `Unhealthy`, never part of readiness(), and never a live provider call.
 * Detail is bounded: modes, closed reasons and counts -- no credential,
 * recipient or provider detail.
 */
final class EmailStatus
{
    public const EVENTS_STALE_AFTER_SECONDS = 3600;

    public function __construct(
        private readonly Repository $config,
        private readonly EmailProviderResolver $providers,
        private readonly EmailEventAdapterResolver $events,
        private readonly ProviderAuthPause $authPause,
        private readonly EmailSignals $signals,
    ) {}

    public function component(): ComponentStatus
    {
        $backlog = $this->signals->backlog();
        $criticalAge = max(array_map(fn (EmailPurpose $p) => $backlog[$p->value]['oldest_age_seconds'], array_filter(EmailPurpose::cases(), fn (EmailPurpose $p) => $p->kind() === EmailKind::Critical)));
        $standardAge = $backlog[EmailPurpose::SchoolCommunication->value]['oldest_age_seconds'];
        $high = (int) $this->config->get('observability.thresholds.backlog_high_seconds');

        $lastEvent = $this->signals->lastEventAt();
        $lastAccepted = $this->signals->lastAcceptedAt();
        $eventsActive = $this->events->active() !== null;
        $eventsStale = $eventsActive && $lastAccepted !== null
            && $lastAccepted->diffInSeconds(now(), true) > self::EVENTS_STALE_AFTER_SECONDS
            && ($lastEvent === null || $lastEvent->lessThan($lastAccepted));

        $reason = match (true) {
            ! $this->providers->enabled() => 'disabled',
            ($blocked = $this->providers->blockedReason()) !== null => $blocked,
            $this->authPause->until() !== null => 'provider_auth_failure',
            max($criticalAge, $standardAge) > $high => 'backlog',
            $eventsStale => 'events_stale',
            default => null,
        };

        return new ComponentStatus('email', $reason === null ? OperationalStatus::Healthy : OperationalStatus::Degraded, $reason, [
            'provider' => $this->providers->mode(),
            'sending_verified' => (bool) $this->config->get('email.sending_verified'),
            'provider_events' => $eventsActive ? 'configured' : 'none',
            'backlog' => $backlog,
            'last_event_at' => $lastEvent?->toIso8601String(),
            'active_suppressions' => $this->signals->activeSuppressions(),
            'retention' => $this->config->get('email.retention_days') === null || $this->config->get('email.retention_days') === '' ? 'unconfigured' : 'configured',
        ]);
    }
}
