<?php

namespace App\Console\Commands;

use App\Support\Operations\CheckResult;
use App\Support\Operations\RendersCheckResults;
use App\Support\ServiceAuth\ServiceAuthContract;
use App\Support\ServiceAuth\ServiceKeyConfigException;
use App\Support\ServiceAuth\ServiceKeyRing;
use App\Support\ServiceAuth\ServiceSigningKey;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * ADR 0053 section 8: read-only report of Laravel's service-authentication
 * configuration -- whether the AI Gateway is configured, the active signing
 * kid and its age, the verification ring's kids, ages and transition end,
 * and the guard rules. Bounded metadata only (kids are Internal): never a
 * private or public key value, an assertion or a secret. Mutates nothing.
 */
class VerifyServiceAuth extends Command
{
    use RendersCheckResults;

    protected $signature = 'platform:verify-service-auth';

    protected $description = 'Verify service-to-service authentication configuration (ADR 0053; read-only, no key material).';

    public function handle(): int
    {
        $now = CarbonImmutable::now('UTC');
        $local = app()->environment(['local', 'testing']);
        $baseUrl = trim((string) config('services.ai_gateway.base_url'));
        $results = [CheckResult::of('legacy_shared_token_absent', config('services.ai_gateway.legacy_service_token_configured') !== true)];

        if ($baseUrl === '') {
            $results[] = CheckResult::of('ai_gateway_configured', true, 'not configured: no service keys needed');

            return $this->renderResults($results);
        }

        $results[] = CheckResult::of('ai_gateway_url_https', $local || str_starts_with($baseUrl, 'https://'), $local ? 'local/testing: plaintext allowed' : '');

        try {
            $key = ServiceSigningKey::fromJwk((string) config('services.ai_gateway.service_signing_key'), $now);
            $age = $key->ageDays($now);
            $results[] = CheckResult::of('signing_key_valid', true, "service=platform kid={$key->kid} age_days={$age}");
            $results[] = CheckResult::of('signing_key_age', $local || $age <= ServiceAuthContract::MAX_KEY_AGE_DAYS,
                $age >= ServiceAuthContract::KEY_AGE_WARNING_DAYS ? 'ROTATE: at or past the day-'.ServiceAuthContract::KEY_AGE_WARNING_DAYS.' warning' : 'max '.ServiceAuthContract::MAX_KEY_AGE_DAYS.' days');
            $results[] = CheckResult::of('signing_key_not_development', $local || ! ServiceAuthContract::isDevelopmentKey($key->kid, $key->publicKey));
        } catch (ServiceKeyConfigException $e) {
            $results[] = CheckResult::of('signing_key_valid', false, $e->violation);
        }

        try {
            $keys = ServiceKeyRing::fromJson((string) config('services.ai_gateway.inbound_verification_keys'), $now)->keys();
            $described = array_map(fn ($k) => "{$k->kid}(age_days={$k->ageDays($now)}".($k->notAfter ? ",transitional_until={$k->notAfter->format('Y-m-d\TH:i:s\Z')}" : ',steady').')', $keys);
            $results[] = CheckResult::of('verification_ring_valid', true, 'service=ai-gateway keys='.implode(' ', $described));
            $oldest = max(array_map(fn ($k) => $k->ageDays($now), $keys));
            $results[] = CheckResult::of('verification_ring_age', $local || $oldest <= ServiceAuthContract::MAX_KEY_AGE_DAYS, "oldest_age_days={$oldest}");
            $results[] = CheckResult::of('verification_ring_not_development', $local || array_filter($keys, fn ($k) => ServiceAuthContract::isDevelopmentKey($k->kid, $k->publicKey)) === []);
        } catch (ServiceKeyConfigException $e) {
            $results[] = CheckResult::of('verification_ring_valid', false, $e->violation);
        }

        $store = config('services.ai_gateway.replay_store') ?? config('cache.default');
        $results[] = CheckResult::of('replay_store_redis', $local || config("cache.stores.{$store}.driver") === 'redis', "store={$store}");
        $results[] = new CheckResult('rotation_and_revocation_drill', CheckResult::EVIDENCE, 'one routine rotation and one emergency revocation in non-production (docs/operations/SERVICE-KEY-ROTATION.md)');

        return $this->renderResults($results);
    }
}
