<?php

namespace App\Http\Controllers\App\Compliance;

use App\Domain\Compliance\Application\AuditLogReviewService;
use App\Http\Controllers\Controller;
use App\Support\Audit\SchoolAuditEventReader;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0L.4 -- the session-authenticated School audit-log review page
 * (ADR 0042 §10). Thin: validates the one accepted parameter (an opaque
 * page cursor) and hands authorization, the read and the access audit to
 * AuditLogReviewService. The School is always the trusted session
 * context, never a request parameter; with no School selected there is
 * nothing to review, so the request is refused (no implicit platform or
 * cross-School access). No filters, search, export or API in v1.
 */
class AuditLogController extends Controller
{
    public function index(Request $request, TenantContext $context, AuditLogReviewService $service): Response
    {
        $school = $context->school();
        abort_if($school === null, 403);

        $validated = $request->validate([
            'cursor' => ['sometimes', 'string', 'max:200', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! SchoolAuditEventReader::isValidCursor($value)) {
                    $fail('The page position is not valid.');
                }
            }],
        ]);

        return Inertia::render('App/Compliance/AuditLog', [
            'log' => $service->review($school, $request->user(), $validated['cursor'] ?? null),
        ]);
    }
}
