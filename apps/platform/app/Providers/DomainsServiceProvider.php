<?php

namespace App\Providers;

use App\Support\Domains\Dns\DomainDnsResolver;
use App\Support\Domains\Dns\FakeDomainDnsResolver;
use App\Support\Domains\Dns\NetDns2DomainResolver;
use App\Support\Domains\HostnameNormalizer;
use App\Support\Domains\Probe\DomainProber;
use App\Support\Domains\Probe\FakeDomainProber;
use App\Support\Domains\Probe\ProbeProof;
use App\Support\Domains\Probe\StreamDomainProber;
use App\Support\Domains\PublicSuffixPolicy;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Phase 0O.8A (ADR 0054): the DNS resolver and TLS prober bindings. The
 * fakes are bound ONLY under the DevOnlySchoolHeaderResolver double guard
 * -- `domains.fakes` AND a local/testing environment -- and production
 * refuses the flag outright (ProductionConfigurationGuard). Everywhere
 * else the real, bounded implementations are used; nothing about them is
 * weakened for development.
 */
class DomainsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HostnameNormalizer::class);
        $this->app->singleton(PublicSuffixPolicy::class, fn () => new PublicSuffixPolicy);

        $this->app->bind(DomainDnsResolver::class, fn (Application $app) => self::fakesEnabled($app)
            ? new FakeDomainDnsResolver($app->make(Repository::class))
            : new NetDns2DomainResolver(array_values(array_map('strval', (array) $app['config']->get('domains.dns.resolvers', [])))));

        $this->app->bind(DomainProber::class, fn (Application $app) => self::fakesEnabled($app)
            ? new FakeDomainProber($app->make(Repository::class), $app->make(ProbeProof::class))
            : new StreamDomainProber($app->make(ProbeProof::class)));
    }

    public static function fakesEnabled(Application $app): bool
    {
        return (bool) $app['config']->get('domains.fakes') && $app->environment(['local', 'testing']);
    }
}
