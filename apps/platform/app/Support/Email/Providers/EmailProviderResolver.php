<?php

namespace App\Support\Email\Providers;

use App\Support\Domains\ReservedHosts;
use App\Support\Email\SenderIdentity;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * ADR 0055 sections 6 and 18: which (single) provider is configured, and
 * whether submitting is allowed right now. `none` is the explicit
 * email-disabled mode: nothing is submitted and nothing claims to be sent.
 * The fake is never resolved outside local/testing, whatever the config
 * says (production also refuses it at boot).
 */
final class EmailProviderResolver
{
    public const PROVIDERS = ['none', 'fake', 'smtp'];

    public function __construct(
        private readonly Container $app,
        private readonly Repository $config,
        private readonly SenderIdentity $sender,
    ) {}

    public function mode(): string
    {
        $mode = (string) $this->config->get('email.provider');

        return in_array($mode, self::PROVIDERS, true) ? $mode : 'none';
    }

    public function enabled(): bool
    {
        return $this->mode() !== 'none';
    }

    public function adapter(): ?EmailProviderAdapter
    {
        return match ($this->mode()) {
            'fake' => $this->app->environment(['local', 'testing']) ? $this->app->make(FakeEmailProvider::class) : null,
            'smtp' => $this->app->make(SmtpEmailProvider::class),
            default => null,
        };
    }

    /**
     * Why nothing may be submitted right now (a closed code), or null. Each
     * of these defers the message -- it never fails or cancels it.
     */
    public function blockedReason(): ?string
    {
        if (! $this->enabled() || $this->adapter() === null) {
            return 'email_disabled';
        }

        if ($this->sender->sendingDomain() === null) {
            return 'sending_domain_missing';
        }

        if ($this->app->environment('production')) {
            // ADR 0055 section 8.4: the sending domain must be one no School
            // can ever claim as a web domain (the platform domain's subtree or
            // a DOMAIN_RESERVED_SUFFIXES entry).
            if (! $this->sendingDomainReserved()) {
                return 'sending_domain_not_reserved';
            }

            // The operator's evidence attestation (ADR 0055 section 18) gates
            // real sending; local/testing work against Mailpit and the fake.
            if (! (bool) $this->config->get('email.sending_verified')) {
                return 'sending_not_verified';
            }
        }

        return null;
    }

    /**
     * ADR 0055 section 4 (the O14 dependency): whether CRITICAL mail --
     * account invitations today, account recovery when O14 lands -- can
     * actually be submitted on this deployment. A future recovery flow must
     * not be declared production-ready, or offered, while this is false
     * (`MAIL_PROVIDER=none`, no verified sending domain...).
     */
    public function criticalEmailAvailable(): bool
    {
        return $this->blockedReason() === null;
    }

    public function sendingDomainReserved(): bool
    {
        $domain = $this->sender->sendingDomain();

        return $domain !== null && $this->app->make(ReservedHosts::class)->isReserved($domain);
    }
}
