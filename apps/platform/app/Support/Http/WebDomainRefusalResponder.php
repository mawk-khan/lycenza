<?php

namespace App\Support\Http;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Support\Header;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * LC-1 (Local Product Completion, M1): the browser side of a domain refusal,
 * for every web page at once.
 *
 * Every module's domain exception carries `getStatusCode()` and
 * `errorCode()`. `bootstrap/app.php` renders that as the JSON envelope for
 * `api/*`; on a web page an uncaught one used to become a 500 (a timetable
 * period overlap, a second open employment, an employment ended before it
 * began). Here, for a browser (HTML/Inertia) request only:
 * - a 4xx refusal becomes a form error under `refusal` -- the page stays,
 *   the shared layout shows the message (RefusalMessage.vue), nothing is
 *   written;
 * - a 404 stays a 404 and a 401/403 stays a refusal page, so a refusal never
 *   says more than the page it came from (another School's row is a 404).
 *
 * The rule itself is unchanged: the same request is still refused. 5xx
 * domain errors, JSON/API requests and every other exception are left
 * alone, so a programming error is still a 500 and still reported.
 * Controllers that map a refusal onto a specific field (MapsDomainRefusals)
 * keep doing so; this is the floor under them.
 */
final class WebDomainRefusalResponder
{
    /** The form-error key RefusalMessage.vue renders. */
    public const ERROR_KEY = 'refusal';

    private const UUID = '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i';

    /**
     * The 4xx status of an expected domain refusal, or null for anything
     * else (an HTTP exception, a 5xx, a programming error).
     */
    public static function refusalStatus(Throwable $e): ?int
    {
        if (! $e instanceof RuntimeException || $e instanceof HttpExceptionInterface
            || ! method_exists($e, 'errorCode') || ! method_exists($e, 'getStatusCode')) {
            return null;
        }

        $status = (int) $e->getStatusCode();

        return $status >= 400 && $status < 500 ? $status : null;
    }

    /**
     * SIS 10.2 (F-1): an Inertia visit (`X-Inertia`) is a browser request
     * whatever its Accept header says -- a crafted one without Accept would
     * otherwise read as an XHR JSON caller and fall through to a 500. API
     * routes are never browser requests.
     */
    public static function isBrowserRequest(Request $request): bool
    {
        if ($request->is('api/*')) {
            return false;
        }

        return (bool) $request->header(Header::INERTIA) || ! $request->expectsJson();
    }

    public function render(Throwable $e, Request $request): ?Response
    {
        $status = self::refusalStatus($e);
        if ($status === null || ! self::isBrowserRequest($request)) {
            return null;
        }

        $mapped = match ($status) {
            404 => new NotFoundHttpException(previous: $e),
            // SIS 2.2: an ExplainedRefusal keeps its 403 and shows its own (safe) message.
            401, 403 => new AccessDeniedHttpException($e instanceof ExplainedRefusal ? self::operatorMessage($e) : '', $e),
            default => ValidationException::withMessages([self::ERROR_KEY => [self::operatorMessage($e)]]),
        };

        return app(ExceptionHandler::class)->render($request, $mapped);
    }

    /**
     * The domain's own message, which is written for the operator in most
     * modules. A message naming internal ids (some were written for
     * developers) is never shown; the stable code stands in for it, and an
     * exception may say it better through `operatorMessage()`.
     */
    public static function operatorMessage(Throwable $e): string
    {
        if (method_exists($e, 'operatorMessage')) {
            return (string) $e->operatorMessage();
        }

        $message = trim($e->getMessage());
        if ($message === '' || preg_match(self::UUID, $message) === 1) {
            return 'This change was not accepted ('.(method_exists($e, 'errorCode') ? $e->errorCode() : 'refused').').';
        }

        return $message;
    }
}
