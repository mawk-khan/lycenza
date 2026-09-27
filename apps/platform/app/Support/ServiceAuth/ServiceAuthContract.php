<?php

namespace App\Support\ServiceAuth;

/**
 * ADR 0053 (Phase 0O.7A): the frozen service-to-service authentication
 * contract -- constants, never configuration. The AI Gateway mirrors every
 * value in services/ai/app/core/service_auth.py (guarded by
 * Tests\Feature\ServiceAuth\ServiceAuthArchitectureTest).
 */
final class ServiceAuthContract
{
    public const ALG = 'EdDSA';

    public const TYP = 'lycenza-service+jwt';

    public const SCHEME = 'lycenza-service';

    public const VERSION = 1;

    public const DEFAULT_LIFETIME_SECONDS = 60;

    public const MAX_LIFETIME_SECONDS = 120;

    public const CLOCK_SKEW_SECONDS = 30;

    public const MAX_ASSERTION_BYTES = 2048;

    public const MAX_KEY_AGE_DAYS = 90;

    public const KEY_AGE_WARNING_DAYS = 76;

    public const MAX_TRANSITION_SECONDS = 24 * 3600;

    /** The closed service-identity catalog (ADR 0053 section 3.1). */
    public const PLATFORM = 'platform';

    public const AI_GATEWAY = 'ai-gateway';

    public const SERVICES = [self::PLATFORM, self::AI_GATEWAY];

    public const AUDIENCE_AI_GATEWAY = 'lycenza-ai-gateway';

    public const AUDIENCE_PLATFORM_INTERNAL_AI = 'lycenza-platform-internal-ai';

    /**
     * Laravel's receiver catalog: route name => the one service scope it
     * requires. Closed; no prefix inference, no wildcard (section 6.1).
     */
    public const ROUTE_SCOPES = [
        'api.internal.ai.tools.school-echo' => 'ai.tools.invoke',
        'api.internal.ai.completions.authorize' => 'ai.completions.authorize',
        'api.internal.ai.audit.store' => 'ai.audit.write',
    ];

    /** Service => the scopes it holds at THIS receiver (section 6.2). */
    public const SERVICE_SCOPES = [
        self::AI_GATEWAY => ['ai.tools.invoke', 'ai.completions.authorize', 'ai.audit.write'],
    ];

    /**
     * Every service scope on either side. None may ever exist in the human
     * `capabilities` catalog (database CHECK capabilities_not_service_scope).
     */
    public const ALL_SERVICE_SCOPES = [
        'ai.tools.invoke', 'ai.completions.authorize', 'ai.audit.write',
        'gateway.tools.invoke', 'gateway.complete',
    ];

    /** Committed development keys: refused outside local/testing by prefix AND public key. */
    public const DEVELOPMENT_KID_PREFIX = 'dev-local-only-';

    public const DEVELOPMENT_PUBLIC_KEYS = [
        'kV3ZvDdDEsrXYdor998DAGfkdizSSsRSzKHycfGuEUQ', // dev-local-only-platform-1
        'WibFMaiB1A6KTbuNEVn8oOm2Qg1pUy1upMSmLbIy1tE', // dev-local-only-ai-gateway-1
    ];

    public const KID_PATTERN = '/\A[a-z0-9][a-z0-9._-]{0,63}\z/';

    public const PATH_PATTERN = '/\A\/[A-Za-z0-9._\/-]{0,255}\z/';

    public const RID_PATTERN = '/\A[A-Za-z0-9._:-]{1,128}\z/';

    public const JTI_PATTERN = '/\A[A-Za-z0-9_-]{16,64}\z/';

    /** The closed internal reason codes (logs and metrics; never a response body). */
    public const FAILURE_CODES = [
        'missing', 'malformed', 'unknown_kid', 'key_expired', 'bad_signature', 'wrong_audience',
        'unknown_service', 'expired', 'not_yet_valid', 'lifetime_exceeded', 'request_mismatch',
        'body_digest_mismatch', 'replayed', 'replay_store_unavailable', 'service_not_authorized',
    ];

    public static function isDevelopmentKey(string $kid, string $publicKey): bool
    {
        return str_starts_with($kid, self::DEVELOPMENT_KID_PREFIX) || in_array($publicKey, self::DEVELOPMENT_PUBLIC_KEYS, true);
    }

    /** No trailing slash, no empty, `.` or `..` segment, only the path grammar (section 4.4). */
    public static function isCanonicalPath(string $path): bool
    {
        if (preg_match(self::PATH_PATTERN, $path) !== 1) {
            return false;
        }
        if ($path === '/') {
            return true;
        }
        foreach (explode('/', substr($path, 1)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
