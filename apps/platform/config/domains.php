<?php

/*
|--------------------------------------------------------------------------
| Hosts and custom School domains -- Phase 0O.8A (ADR 0054)
|--------------------------------------------------------------------------
|
| Every request Host is classified exactly (App\Support\Domains\HostClassifier):
| the platform host (the APP_URL host) and its configured aliases, the
| private internal hosts, an ACTIVE custom School domain, or unknown (421).
| Lists are comma-separated exact hostnames -- never patterns.
|
| Custom domains are OFF unless CUSTOM_DOMAINS_ENABLED=true. Disabled is a
| safe, complete mode: no domain can be claimed or resolved and the Host
| boundary still applies. Enabled production requires the edge target, the
| probe key and the DNS resolvers (App\Support\Configuration\
| ProductionConfigurationGuard).
|
| The two development switches below are honoured ONLY in `local`/`testing`
| (the DevOnlySchoolHeaderResolver double guard) and are refused in
| production.
|
*/

$list = static fn (?string $value): array => array_values(array_filter(array_map(
    static fn (string $entry): string => trim($entry),
    explode(',', (string) $value),
), static fn (string $entry): bool => $entry !== ''));

return [

    'enabled' => (bool) env('CUSTOM_DOMAINS_ENABLED', false),

    // Exact extra platform hostnames (serve everything the platform host serves).
    'platform_aliases' => $list(env('DOMAIN_PLATFORM_ALIASES')),

    // Exact private hostnames that serve ONLY /api/internal/ai/* and health
    // (ADR 0053 section 10: the private TLS listener the Gateway calls).
    'internal_hosts' => $list(env('INTERNAL_HOSTS')),

    // No School may claim these exact names, or any name under the listed
    // suffixes. The platform host's registrable domain, the aliases, the
    // internal hosts and the edge target are always reserved as well.
    'reserved_hosts' => $list(env('DOMAIN_RESERVED_HOSTS')),
    'reserved_suffixes' => $list(env('DOMAIN_RESERVED_SUFFIXES')),

    // ADR 0054 section 5.2: where a School points its hostname. Never an IP
    // literal in application logic -- deployment configuration only.
    'edge' => [
        'cname_target' => env('DOMAIN_EDGE_CNAME_TARGET'),
        'addresses' => $list(env('DOMAIN_EDGE_ADDRESSES')),
    ],

    // Highly Sensitive (O4): the HMAC key proving an edge routes a hostname
    // to THIS deployment (ADR 0054 section 6.2). No default.
    'probe_key' => env('DOMAIN_PROBE_KEY'),

    // ADR 0054 section 4.5: public recursive resolvers (IP addresses), never
    // an internal split-horizon resolver. Bounds are frozen, not tunable.
    'dns' => [
        'resolvers' => $list(env('DOMAIN_DNS_RESOLVERS')),
    ],

    // Environment-gated development switches (local/testing only).
    'fakes' => (bool) env('DOMAIN_FAKES_ENABLED', false),
    'allow_development_hosts' => (bool) env('DOMAIN_ALLOW_DEVELOPMENT_HOSTS', false),

    // Cross-host sign-in continuity (ADR 0054 amendment): one-time tickets
    // in this cache store (Redis in production; null = the default store).
    'handoff_store' => env('SESSION_HANDOFF_STORE') ?: null,

    // Scheduled checks per run (ADR 0054 section 7.1: bounded).
    'checks_per_run' => (int) env('DOMAIN_CHECKS_PER_RUN', 50),

];
