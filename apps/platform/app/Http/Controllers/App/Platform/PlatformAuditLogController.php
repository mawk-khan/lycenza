<?php

namespace App\Http\Controllers\App\Platform;

use App\Domain\Platform\Application\Audit\PlatformAuditLogReviewService;
use App\Http\Controllers\Controller;
use App\Support\Audit\PlatformAuditEventReader;
use App\Support\Auth\Mfa\Exceptions\MfaException;
use App\Support\Auth\RendersAuthJsonErrors;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 0N.7 (ADR 0046 section 9): `/app/platform/audit-log`, the
 * context-neutral platform audit review. Everything is decided by
 * PlatformAuditLogReviewService (capability, then MFA assurance). An MFA
 * refusal keeps the existing codes and statuses (`403
 * mfa_required_not_enrolled`, `401 mfa_step_up_required`): JSON callers
 * get RequireMfa's own JSON body; a browser gets a page explaining what to
 * do, with the same status, instead of raw JSON.
 */
class PlatformAuditLogController extends Controller
{
    use RendersAuthJsonErrors;

    public function index(Request $request, PlatformAuditLogReviewService $service): Response
    {
        $validated = $request->validate([
            'cursor' => ['sometimes', 'string', 'max:200', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! PlatformAuditEventReader::isValidCursor($value)) {
                    $fail('The page position is not valid.');
                }
            }],
        ]);

        try {
            $log = $service->review($request, $request->user(), $validated['cursor'] ?? null);
        } catch (MfaException $e) {
            if ($request->expectsJson() && $request->header('X-Inertia') !== 'true') {
                return $this->jsonError($e);
            }

            return Inertia::render('App/Platform/MfaRequired', [
                'code' => $e->errorCode(),
            ])->toResponse($request)->setStatusCode($e->getStatusCode());
        }

        return Inertia::render('App/Platform/AuditLog', ['log' => $log])->toResponse($request);
    }
}
