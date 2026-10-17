<?php

namespace Tests\Unit\Http;

use App\Support\Http\WebDomainRefusalResponder;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * LC-1: which exceptions count as an expected domain refusal, and what an
 * operator may read. The browser rendering itself is proven in
 * Tests\Feature\Http\WebDomainRefusalTest.
 */
class WebDomainRefusalResponderTest extends TestCase
{
    private function refusal(int $status, string $message, string $code = 'TEST_REFUSED'): RuntimeException
    {
        return new class($status, $code, $message) extends RuntimeException
        {
            public function __construct(private readonly int $status, private readonly string $code_, string $message)
            {
                parent::__construct($message);
            }

            public function getStatusCode(): int
            {
                return $this->status;
            }

            public function errorCode(): string
            {
                return $this->code_;
            }
        };
    }

    #[Test]
    public function only_a_4xx_domain_refusal_is_a_refusal(): void
    {
        foreach ([400, 403, 404, 409, 422] as $status) {
            $this->assertSame($status, WebDomainRefusalResponder::refusalStatus($this->refusal($status, 'x')));
        }

        $this->assertNull(WebDomainRefusalResponder::refusalStatus($this->refusal(500, 'boom')));
        $this->assertNull(WebDomainRefusalResponder::refusalStatus(new RuntimeException('plain')));
        $this->assertNull(WebDomainRefusalResponder::refusalStatus(new NotFoundHttpException));
    }

    #[Test]
    public function an_operator_never_reads_an_internal_id(): void
    {
        $this->assertSame('This time range overlaps.', WebDomainRefusalResponder::operatorMessage($this->refusal(422, 'This time range overlaps.')));
        $this->assertSame(
            'This change was not accepted (HR_EMPLOYMENT_OVERLAP).',
            WebDomainRefusalResponder::operatorMessage($this->refusal(422, 'Employee 01a148e5-2389-7d7d-81c9-b7749128253a overlaps.', 'HR_EMPLOYMENT_OVERLAP')),
        );
        $this->assertSame('This change was not accepted (TEST_REFUSED).', WebDomainRefusalResponder::operatorMessage($this->refusal(422, '  ')));
    }

    #[Test]
    public function api_and_json_requests_keep_their_envelope(): void
    {
        $responder = new WebDomainRefusalResponder;

        $this->assertNull($responder->render($this->refusal(422, 'x'), Request::create('/api/v1/schools/x/things', 'POST')));
        $json = Request::create('/app/thing', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        $this->assertNull($responder->render($this->refusal(422, 'x'), $json));
        $this->assertFalse(WebDomainRefusalResponder::isBrowserRequest($json));
        $this->assertTrue(WebDomainRefusalResponder::isBrowserRequest(Request::create('/app/thing', 'POST')));
    }
}
