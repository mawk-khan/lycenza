<?php

namespace App\Domain\HR\Application\Exceptions;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SR.4 (ADR 0071 §26.2): an actor tried to change their OWN place in the
 * HR identity substrate -- link an Employee to themselves, or change the
 * link, employment, assignments, reporting line, lifecycle or staff
 * attendance of the Employee linked to them. Those facts decide who
 * ActingEmployee resolves to (teaching ownership, self-service, leave
 * decisions, payslips), so another holder of the HR capability makes them.
 *
 * Rendered by itself, so every surface refuses it the same way: `/api/*`
 * through the shared envelope (403 `HR_SELF_ADMINISTRATION`), another JSON
 * request as a 403, and a browser form as a redirect back with the message.
 */
class HrSelfAdministrationException extends HrException
{
    public const CODE = 'HR_SELF_ADMINISTRATION';

    public function __construct(public readonly string $operation)
    {
        parent::__construct(403, self::CODE, 'You cannot change your own employee identity, employment or attendance. Another HR administrator must do this.');
    }

    public function render(Request $request): ?Response
    {
        if ($request->is('api/*')) {
            return null;
        }

        if ($request->expectsJson()) {
            return response()->json(['error' => ['message' => $this->getMessage(), 'status' => 403, 'code' => self::CODE]], 403);
        }

        return back()->withErrors(['self_administration' => $this->getMessage()]);
    }
}
