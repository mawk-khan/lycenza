<?php

namespace App\Http\Middleware\Api;

use App\Models\ApiClient;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0O.3 (ADR 0049 sections 3-4): after `auth:partner`, the request
 * runs under exactly the ONE School its credential is bound to -- never a
 * School named by the URL, a header or the body (partner routes take no
 * `{school}` parameter; CLAUDE.md rules 19-20). A School that is not
 * `active` gets the same non-disclosing 404 as `school-membership`; the
 * credential is untouched and works again after a resume. Tenant data
 * stays under forced RLS; the context is cleared in `finally`.
 */
class EstablishPartnerContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $client = ApiClient::fromRequest($request);

        if ($client === null) {
            throw new AuthenticationException;
        }

        $school = School::query()->find($client->school_id);

        if ($school === null || ! $school->isActive()) {
            return response()->json([
                'error' => [
                    'message' => 'Not found.',
                    'status' => 404,
                    'code' => null,
                    'requestId' => $request->attributes->get('request_id'),
                    'errors' => null,
                ],
            ], 404);
        }

        $this->context->set($school);
        Context::add('api_client_id', $client->id);

        try {
            return $next($request);
        } finally {
            $this->context->clearAll();
            Context::forget('api_client_id');
        }
    }
}
