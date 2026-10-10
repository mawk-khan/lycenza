<?php

namespace App\Domain\Payroll\Application\Exceptions;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SR.4 (ADR 0071 §26.4): nobody sets their own pay. A payroll maker never
 * assigns compensation to, or records a manual override or correction delta
 * for, the EmploymentRecord of the Employee linked to them -- another holder
 * of the same capability does (the checker's approval stays the second
 * control). Rendered like HrSelfAdministrationException on every surface.
 */
class PayrollSelfAdministrationException extends PayrollException
{
    public const CODE = 'PAYROLL_SELF_ADMINISTRATION';

    public function __construct(public readonly string $operation)
    {
        parent::__construct(403, self::CODE, 'You cannot set your own pay. Another payroll administrator must do this.');
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
