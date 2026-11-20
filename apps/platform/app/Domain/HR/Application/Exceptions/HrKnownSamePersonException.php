<?php

namespace App\Domain\HR\Application\Exceptions;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HR.R1-C35 WO-24 (RF1-N2, WO18-N1; rule 152): the actor's account is not the Employee's CURRENT link -- that
 * stays HrSelfAdministrationException, checked first -- but the actor, or an account known to be the same person,
 * holds an uncorrected link (current or ended) to it: as far as anything here knows, the actor IS that Employee.
 * Raised by an unlink and by an employment end (the direct record of a death). It names no account and no link.
 *
 * Rendered like HrSelfAdministrationException: `/api/*` through the shared envelope (403 `HR_KNOWN_SAME_PERSON`),
 * another JSON request as a 403, a browser form as a redirect back with the message.
 */
class HrKnownSamePersonException extends HrException
{
    public const CODE = 'HR_KNOWN_SAME_PERSON';

    public function __construct(public readonly string $operation)
    {
        parent::__construct(403, self::CODE, 'One of your accounts is known to belong to this employee. Another HR administrator must do this.');
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
