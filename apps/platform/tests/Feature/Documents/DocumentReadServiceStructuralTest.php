<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Application\DocumentReadService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Phase 0E.3 (checklist §28/§56) -- structural proof, not behavioral:
 * DocumentReadService's public API only ever starts from a Document
 * identity resolved under tenancy, never from a raw storage key/path.
 * This is what makes an orphan object (the existing 0E.2 P3 residual --
 * a private object with no `documents` row referencing it, left behind
 * only if a DB transaction AND its own compensation both fail)
 * structurally unaddressable through this service: there is no method
 * that accepts a bucket/key/path argument for a caller to guess or
 * enumerate.
 */
class DocumentReadServiceStructuralTest extends TestCase
{
    #[Test]
    public function the_public_api_is_limited_to_metadata_and_content(): void
    {
        $reflection = new ReflectionClass(DocumentReadService::class);
        $publicMethodNames = array_map(
            fn (ReflectionMethod $method) => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        $publicMethodNames = array_values(array_diff($publicMethodNames, ['__construct']));

        sort($publicMethodNames);

        $this->assertSame(['content', 'metadata'], $publicMethodNames);
    }

    #[Test]
    public function no_public_method_accepts_a_raw_storage_path_or_key_argument(): void
    {
        $reflection = new ReflectionClass(DocumentReadService::class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }

            $parameterNames = array_map(fn ($p) => strtolower($p->getName()), $method->getParameters());

            foreach ($parameterNames as $name) {
                $this->assertStringNotContainsStringIgnoringCase('path', $name, "{$method->getName()}() must not accept a raw storage path argument.");
                $this->assertStringNotContainsStringIgnoringCase('key', $name, "{$method->getName()}() must not accept a raw object-key argument.");
                $this->assertStringNotContainsStringIgnoringCase('disk', $name, "{$method->getName()}() must not accept a caller-selected disk argument.");
            }
        }
    }

    #[Test]
    public function no_signed_or_public_url_method_exists(): void
    {
        $source = file_get_contents((new ReflectionClass(DocumentReadService::class))->getFileName());

        $this->assertStringNotContainsString('temporaryUrl', $source);
        $this->assertStringNotContainsString('->url(', $source);
        $this->assertStringNotContainsString('signedUrl', $source);
    }

    #[Test]
    public function content_never_buffers_the_whole_file_via_storage_get(): void
    {
        $source = file_get_contents((new ReflectionClass(DocumentReadService::class))->getFileName());

        $this->assertStringContainsString('readStream', $source);
        $this->assertStringNotContainsString('->get(', $source);
    }
}
