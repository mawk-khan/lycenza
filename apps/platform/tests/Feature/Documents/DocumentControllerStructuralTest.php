<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Http\Controllers\DocumentController;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Phase 0E.5 (gates 67-70/85/86) -- structural proof that
 * DocumentController stays a thin transport adapter: no direct
 * Storage/authorization/audit calls, no signed/temporary URL, no
 * Range/206 support, and whole-file buffering never appears (only
 * `streamDownload`/`fpassthru`, never `Storage::get(`).
 */
class DocumentControllerStructuralTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents((new ReflectionClass(DocumentController::class))->getFileName());
    }

    #[Test]
    public function the_controller_never_touches_storage_directly(): void
    {
        $source = $this->source();

        foreach (['Storage::', 'Filesystem::', '->readStream(', '->put(', '->delete(', 'temporaryUrl', 'signedUrl'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "DocumentController must not call {$forbidden} -- storage I/O belongs to the Application services.");
        }
    }

    #[Test]
    public function the_controller_never_performs_its_own_authorization(): void
    {
        $source = $this->source();

        $this->assertStringNotContainsString('Gate::authorize', $source);
        $this->assertStringNotContainsString('->can(', $source);
        $this->assertStringNotContainsString('authorizeCapability', $source);
    }

    #[Test]
    public function the_controller_never_writes_an_audit_event_directly(): void
    {
        $this->assertStringNotContainsString('AuditRecorder', $this->source());
    }

    #[Test]
    public function no_public_or_signed_url_capability_exists(): void
    {
        $source = $this->source();

        foreach (['temporaryUrl', 'signedUrl', '->url(', 'Range', '206'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }
    }

    #[Test]
    public function content_streams_and_never_buffers_the_whole_file(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('streamDownload', $source);
        $this->assertStringContainsString('fpassthru', $source);
        $this->assertStringNotContainsString('->get(', $source);
        $this->assertStringNotContainsString('file_get_contents', $source);
        $this->assertStringNotContainsString('stream_get_contents', $source);
    }

    #[Test]
    public function no_document_or_employee_route_parameter_is_implicitly_eloquent_bound(): void
    {
        $reflection = new ReflectionClass(DocumentController::class);

        foreach ([
            'storeForEmployee', 'indexForEmployee', 'sensitiveIndexForEmployee',
            'storeForLearningContent', 'indexForLearningContent',
            'storeForAssignment', 'indexForAssignment',
            'show', 'content', 'archive',
        ] as $method) {
            $parameters = $reflection->getMethod($method)->getParameters();
            foreach ($parameters as $parameter) {
                $type = $parameter->getType();
                if ($type !== null && str_contains((string) $type, 'Document')) {
                    $this->fail("{$method}() must accept the Document id as a raw string, never an implicitly-bound Document model.");
                }
                if ($type !== null && str_contains((string) $type, 'Employee')) {
                    $this->fail("{$method}() must accept the Employee id as a raw string, never an implicitly-bound Employee model.");
                }
                if ($type !== null && str_contains((string) $type, 'LearningContent')) {
                    $this->fail("{$method}() must accept the LearningContent id as a raw string, never an implicitly-bound LearningContent model.");
                }
                if ($type !== null && str_contains((string) $type, 'Assignment')) {
                    $this->fail("{$method}() must accept the Assignment id as a raw string, never an implicitly-bound Assignment model.");
                }
            }
        }

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function only_the_ten_expected_public_actions_exist(): void
    {
        // Phase 0I.3 added storeForAssignment/indexForAssignment -- the
        // Assignment owner-arm counterpart of the pre-existing
        // Employee/LearningContent pairs -- widening this closed list
        // from eight to ten.
        $reflection = new ReflectionClass(DocumentController::class);
        $publicMethods = array_map(
            fn ($m) => $m->getName(),
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        $publicMethods = array_values(array_diff($publicMethods, ['__construct']));
        sort($publicMethods);

        $this->assertSame(
            [
                'archive', 'content', 'indexForAssignment', 'indexForEmployee', 'indexForLearningContent',
                'sensitiveIndexForEmployee', 'show', 'storeForAssignment', 'storeForEmployee', 'storeForLearningContent',
            ],
            $publicMethods,
        );
    }
}
