<?php

namespace App\Support\Idempotency;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Computes a deterministic SHA-256 fingerprint of the LOGICAL request
 * (section 8/9) -- HTTP method, canonical route/action identity,
 * normalized route parameters, canonicalized JSON body, School, and
 * actor. Deliberately excludes transport noise that varies between two
 * otherwise-identical retries: User-Agent, request/correlation/trace
 * IDs, Authorization token, timestamps.
 *
 * Canonicalization is JSON-only: object keys are recursively sorted so
 * logically-equivalent payloads with different key ordering produce the
 * same fingerprint; array (list) order is preserved because it is
 * semantically meaningful. Multipart uploads, streaming request bodies,
 * and very large payloads are explicitly OUT OF SCOPE for this
 * checkpoint -- see docs/architecture/RELIABILITY.md's idempotency
 * section for the documented limitation. A future module accepting
 * uploads under an idempotency key must fingerprint upload metadata
 * (filename, size, checksum) rather than raw bytes, and will need its
 * own review at that time.
 */
class RequestFingerprint
{
    public function compute(Request $request, string $routeAction, string $schoolId, string $actorType, string $actorId): string
    {
        $payload = [
            'method' => strtoupper($request->method()),
            'route' => $routeAction,
            'school_id' => $schoolId,
            'actor' => "{$actorType}:{$actorId}",
            'route_params' => $this->normalizedRouteParams($request),
            'body' => $this->canonicalize($this->jsonBody($request)),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        if (! str_contains((string) $request->header('Content-Type'), 'json')) {
            return [];
        }

        $decoded = json_decode((string) $request->getContent(), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, string>
     */
    private function normalizedRouteParams(Request $request): array
    {
        $route = $request->route();

        if ($route === null) {
            return [];
        }

        $params = [];
        foreach ($route->parameters() as $key => $value) {
            $params[$key] = $value instanceof Model ? (string) $value->getKey() : (string) $value;
        }

        ksort($params);

        return $params;
    }

    /**
     * Recursively sorts associative-array (JSON object) keys so key
     * ordering never affects the fingerprint. Numeric-indexed (JSON
     * array/list) order is left untouched -- it is semantically
     * significant.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $data): array
    {
        if (array_is_list($data)) {
            return array_map(fn ($value) => is_array($value) ? $this->canonicalize($value) : $value, $data);
        }

        ksort($data);

        foreach ($data as $key => $value) {
            $data[$key] = is_array($value) ? $this->canonicalize($value) : $value;
        }

        return $data;
    }
}
