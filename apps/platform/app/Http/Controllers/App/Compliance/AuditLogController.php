<?php

namespace App\Http\Controllers\App\Compliance;

use App\Domain\Compliance\Application\AuditLogReviewService;
use App\Domain\Compliance\Application\InvalidAuditLogFilterException;
use App\Http\Controllers\Controller;
use App\Support\Audit\SchoolAuditEventReader;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0L.4 -- the session-authenticated School audit-log review page
 * (ADR 0042 §10). Thin: validates the one accepted parameter (an opaque
 * page cursor) and hands authorization, the read and the access audit to
 * AuditLogReviewService. The School is always the trusted session
 * context, never a request parameter; with no School selected there is
 * nothing to review, so the request is refused (no implicit platform or
 * cross-School access). No search, export or API.
 *
 * ORG.9B (ADR 0076 §68), 9B-4: optional filters -- event type or family,
 * subject label, School-local from/to dates -- validated here for shape and
 * by the service against the School's own values. They only narrow.
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
            'event' => ['sometimes', 'nullable', 'string', 'max:150', 'regex:/^[a-z0-9_]+(\.[a-z0-9_]+)*$/'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9_]+$/'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $filters = [
            'event' => $validated['event'] ?? null,
            'subject' => $validated['subject'] ?? null,
            'from' => $validated['from'] ?? null,
            'to' => $validated['to'] ?? null,
        ];
        if ($filters['from'] !== null && $filters['to'] !== null && $filters['from'] > $filters['to']) {
            throw ValidationException::withMessages(['from' => 'The start date must be on or before the end date.']);
        }

        try {
            $log = $service->review($school, $request->user(), $validated['cursor'] ?? null, $filters);
        } catch (InvalidAuditLogFilterException $e) {
            throw ValidationException::withMessages([$e->field => 'Choose a value from the list.']);
        }

        return Inertia::render('App/Compliance/AuditLog', [
            'log' => $log,
            'filters' => array_map(fn (?string $v) => $v ?? '', $filters),
            'timezone' => $school->timezone ?: 'UTC',
        ]);
    }
}
