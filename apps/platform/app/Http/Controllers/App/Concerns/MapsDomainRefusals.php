<?php

namespace App\Http\Controllers\App\Concerns;

use Closure;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * SWC.2/SWC.3 (ADR 0073 sections 2.5, 19): the web side of a domain
 * refusal. Every module's domain exception (Academic Structure, Transport,
 * Hostel, Admissions, Library, ...) carries `getStatusCode()` and
 * `errorCode()`, but `bootstrap/app.php` renders that envelope for `api/*`
 * only, so on an Inertia page it used to become a 500. Here a 4xx refusal
 * becomes a field error on the form that caused it -- the house pattern
 * (StudentSubjectEnrollmentController) -- with the domain's own message, and
 * a 404 stays a 404 (never revealing whether another School's row exists).
 * The refusal itself, and every write it prevents, is unchanged. Anything
 * else (a programming error) is rethrown.
 */
trait MapsDomainRefusals
{
    /**
     * @template T
     *
     * @param  Closure(): T  $action
     * @return T
     */
    private function withFormErrors(string $field, Closure $action): mixed
    {
        try {
            return $action();
        } catch (RuntimeException $e) {
            if (! method_exists($e, 'errorCode') || ! method_exists($e, 'getStatusCode')) {
                throw $e;
            }

            $status = (int) $e->getStatusCode();

            if ($status === 404) {
                throw new NotFoundHttpException(previous: $e);
            }
            if ($status < 400 || $status >= 500) {
                throw $e;
            }

            throw ValidationException::withMessages([$field => [$e->getMessage()]]);
        }
    }
}
