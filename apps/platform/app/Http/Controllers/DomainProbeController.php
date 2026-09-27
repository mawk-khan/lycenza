<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ClassifyRequestHost;
use App\Support\Domains\HostClassification;
use App\Support\Domains\Probe\ProbeProof;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0O.8A (ADR 0054 section 6.2 step 4):
 * `GET /.well-known/lycenza-domain-probe?n=<nonce>` on a custom domain Host
 * that is tls_pending, active or suspended (ClassifyRequestHost refused
 * every other Host with 421 before this runs). The body is ONLY
 * HMAC-SHA256(DOMAIN_PROBE_KEY, "<canonical hostname>|<nonce>") -- never the
 * key, never health, database or School detail. A malformed nonce or no
 * configured key is the fixed 404. Registered outside the `web` group: no
 * session, no cookie, no CSRF.
 */
class DomainProbeController extends Controller
{
    public function __invoke(Request $request, ProbeProof $proof): Response
    {
        $classification = HostClassification::for($request);
        $hostname = $classification !== null && in_array($classification->class, [HostClassification::PROBE, HostClassification::SCHOOL], true)
            ? $classification->hostname
            : null;

        $answer = $hostname !== null ? $proof->for($hostname, (string) $request->query('n', '')) : null;

        if ($answer === null) {
            return ClassifyRequestHost::notFound();
        }

        return response($answer, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
