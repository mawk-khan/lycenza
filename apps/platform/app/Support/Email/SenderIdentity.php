<?php

namespace App\Support\Email;

use App\Support\Domains\HostnameNormalizer;
use Illuminate\Contracts\Config\Repository;

/**
 * ADR 0055 section 8: the ONLY place a From identity is built.
 *
 * - Address: `<mailbox>@<MAIL_SENDING_DOMAIN>` -- the mailbox comes from
 *   the code-owned catalog (v1: `notifications`), never a free-form local
 *   part; the domain is deployment configuration, never a School's custom
 *   web domain (O9 authorizes nothing about email).
 * - Display name: `"<School name> via <MAIL_FROM_NAME>"` for School mail,
 *   `MAIL_FROM_NAME` otherwise -- sanitized (HeaderValue), at most 64
 *   characters, falling back to the platform name. The School's stored
 *   name is never modified.
 * - Reply-To: none in v1. No School address is verified (`schools.email`
 *   is free profile data; guardian contacts' `verified_at` has no
 *   workflow), so nothing -- School, guardian or request input -- ever
 *   becomes a Reply-To (guard-tested).
 */
final class SenderIdentity
{
    /** The closed From mailbox catalog. A new mailbox needs an ADR 0055 amendment and matching DNS evidence. */
    public const MAILBOXES = ['notifications'];

    public const DEFAULT_MAILBOX = 'notifications';

    public function __construct(private readonly Repository $config) {}

    /** The configured sending domain, or null when absent or malformed. */
    public function sendingDomain(): ?string
    {
        return self::validSendingDomain((string) $this->config->get('email.sending_domain'));
    }

    public static function validSendingDomain(string $value): ?string
    {
        $host = (new HostnameNormalizer)->canonicalRequestHost(trim($value));

        return $host !== null && substr_count($host, '.') >= 1 && preg_match('/^[a-z0-9.-]+$/', $host) === 1 ? $host : null;
    }

    public function fromAddress(string $mailbox = self::DEFAULT_MAILBOX): string
    {
        if (! in_array($mailbox, self::MAILBOXES, true)) {
            throw new EmailConfigurationException('mail_from_mailbox_invalid');
        }

        $domain = $this->sendingDomain() ?? throw new EmailConfigurationException('mail_sending_domain_missing');

        return "{$mailbox}@{$domain}";
    }

    public function platformName(): string
    {
        return HeaderValue::displayName((string) $this->config->get('email.from_name'), 'Lycenza');
    }

    public function displayName(?string $schoolName): string
    {
        $platform = $this->platformName();

        if ($schoolName === null) {
            return $platform;
        }

        $suffix = " via {$platform}";
        $school = HeaderValue::displayName($schoolName, '');
        $room = HeaderValue::DISPLAY_NAME_MAX - mb_strlen($suffix);

        if ($school === '' || $room < 1) {
            return $platform;
        }

        return HeaderValue::displayName(trim(mb_substr($school, 0, $room)).$suffix, $platform);
    }
}
