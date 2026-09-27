<?php

namespace App\Console\Commands;

use App\Support\ServiceAuth\Base64Url;
use App\Support\ServiceAuth\ServiceAuthContract;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * ADR 0053 section 3.3: generate one Ed25519 service keypair OUTSIDE request
 * processing (never at boot), for the operator to place in the managed
 * secret store (private) and the receiver's ring (public).
 *
 * The private JWK is written ONLY to `<output>/<kid>.private.jwk` with mode
 * 0600 -- never printed, logged or stored in the database; the public JWK is
 * written beside it and printed. Refuses to write inside the application
 * tree (a key must never land in the repository or an image) and never
 * overwrites. `--non-production` forces the `dev-local-only-` kid prefix,
 * which every production guard refuses.
 */
class GenerateServiceKey extends Command
{
    protected $signature = 'platform:service-key-generate
        {service : The calling service the key signs for (platform|ai-gateway)}
        {--output= : An existing directory OUTSIDE the application for the two JWK files}
        {--kid= : Key id (default <service>-<YYYYMMDD>-1)}
        {--non-production : Development/test key: kid prefixed dev-local-only- (refused in production)}';

    protected $description = 'Generate an Ed25519 service keypair (ADR 0053); the private JWK goes only to a 0600 file.';

    public function handle(): int
    {
        $service = (string) $this->argument('service');
        if (! in_array($service, ServiceAuthContract::SERVICES, true)) {
            $this->error('Refused: the service must be one of: '.implode(', ', ServiceAuthContract::SERVICES).'.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now('UTC');
        $kid = (string) ($this->option('kid') ?: $service.'-'.$now->format('Ymd').'-1');
        if ($this->option('non-production') && ! str_starts_with($kid, ServiceAuthContract::DEVELOPMENT_KID_PREFIX)) {
            $kid = ServiceAuthContract::DEVELOPMENT_KID_PREFIX.$kid;
        }
        if (preg_match(ServiceAuthContract::KID_PATTERN, $kid) !== 1) {
            $this->error('Refused: the kid must match '.ServiceAuthContract::KID_PATTERN.'.');

            return self::FAILURE;
        }

        $output = realpath((string) $this->option('output'));
        $application = realpath(base_path());
        if ($output === false || ! is_dir($output) || ! is_writable($output)) {
            $this->error('Refused: --output must be an existing, writable directory.');

            return self::FAILURE;
        }
        if ($application !== false && ($output === $application || str_starts_with($output.'/', $application.'/'))) {
            $this->error('Refused: never write a key inside the application tree.');

            return self::FAILURE;
        }

        $privatePath = "{$output}/{$kid}.private.jwk";
        $publicPath = "{$output}/{$kid}.public.jwk";
        if (file_exists($privatePath) || file_exists($publicPath)) {
            $this->error('Refused: a key file with this kid already exists (never overwritten).');

            return self::FAILURE;
        }

        $seed = random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $pair = sodium_crypto_sign_seed_keypair($seed);
        $public = ['kty' => 'OKP', 'crv' => 'Ed25519', 'kid' => $kid, 'x' => Base64Url::encode(sodium_crypto_sign_publickey($pair)), 'created' => $now->toDateString()];
        $private = [...array_slice($public, 0, 4), 'd' => Base64Url::encode($seed), 'created' => $public['created']];

        $umask = umask(0077);
        try {
            file_put_contents($privatePath, json_encode($private, JSON_UNESCAPED_SLASHES)."\n");
            chmod($privatePath, 0600);
        } finally {
            umask($umask);
        }
        file_put_contents($publicPath, json_encode($public, JSON_UNESCAPED_SLASHES)."\n");
        sodium_memzero($seed);

        $this->line("service={$service} kid={$kid} created={$public['created']}");
        $this->line('public JWK (for the receiver ring): '.json_encode($public, JSON_UNESCAPED_SLASHES));
        $this->line("private JWK written to {$privatePath} (mode 0600) -- move it into the managed secret store, then destroy the file.");

        return self::SUCCESS;
    }
}
