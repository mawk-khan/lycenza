<?php

namespace App\Support\Email\Providers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * ADR 0060 (E18; section 28): the SendGrid configuration, validated in ONE
 * place. Every value is explicit -- nothing defaults to live delivery.
 *
 * - Production is refused outright (`production_not_authorised`): the E17
 *   legal/processor determination is outstanding, and only a dated ADR 0060
 *   amendment may change that (with a code change here).
 * - Any missing or malformed value is a closed violation code; the
 *   resolver then blocks submission (`provider_misconfigured`) and every
 *   message waits. There is no fallback to another transport.
 */
final class SendGridConfiguration
{
    /** The closed host set (ADR 0060 section 6); never a free URL. */
    public const HOSTS = ['global' => 'api.sendgrid.com', 'eu' => 'api.eu.sendgrid.com'];

    public const MODES = ['sandbox', 'delivery'];

    public function __construct(
        private readonly Repository $config,
        private readonly Application $app,
    ) {}

    /** A closed code describing why SendGrid may not be used here, or null. */
    public function violation(): ?string
    {
        if ($this->app->environment('production')) {
            return 'production_not_authorised';
        }

        $key = trim((string) $this->config->get('email.sendgrid.api_key'));
        // SendGrid keys are `SG.<id>.<secret>`; a placeholder never passes.
        if (preg_match('/^SG\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{16,}$/', $key) !== 1) {
            return 'api_key_invalid';
        }

        if (! array_key_exists((string) $this->config->get('email.sendgrid.region'), self::HOSTS)) {
            return 'region_invalid';
        }

        if (! in_array($this->config->get('email.sendgrid.mode'), self::MODES, true)) {
            return 'mode_invalid';
        }

        $domains = $this->testRecipientDomains();
        if ($domains === [] || array_filter($domains, fn (string $d) => preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $d) !== 1) !== []) {
            return 'test_recipient_domains_invalid';
        }

        // ADR 0055 section 13 / ADR 0060 section 10: tracking stays off.
        if ((bool) $this->config->get('email.tracking.opens') || (bool) $this->config->get('email.tracking.clicks')) {
            return 'tracking_enabled';
        }

        return null;
    }

    public function valid(): bool
    {
        return $this->violation() === null;
    }

    public function apiKey(): string
    {
        return trim((string) $this->config->get('email.sendgrid.api_key'));
    }

    public function endpoint(): string
    {
        return 'https://'.self::HOSTS[(string) $this->config->get('email.sendgrid.region')].'/v3/mail/send';
    }

    /** Only an explicit `delivery` is non-sandbox; anything else is sandbox. */
    public function sandbox(): bool
    {
        return $this->config->get('email.sendgrid.mode') !== 'delivery';
    }

    public function mode(): ?string
    {
        $mode = $this->config->get('email.sendgrid.mode');

        return in_array($mode, self::MODES, true) ? $mode : null;
    }

    /** @return list<string> */
    public function testRecipientDomains(): array
    {
        return array_values(array_map(fn ($d) => strtolower(trim((string) $d)), (array) $this->config->get('email.sendgrid.test_recipient_domains', [])));
    }

    /** OD-1: synthetic / operator-controlled test mailboxes only. */
    public function recipientPermitted(string $address): bool
    {
        $at = strrpos($address, '@');
        if ($at === false) {
            return false;
        }

        return in_array(strtolower(substr($address, $at + 1)), $this->testRecipientDomains(), true);
    }
}
