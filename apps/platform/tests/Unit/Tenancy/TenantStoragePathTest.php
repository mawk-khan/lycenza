<?php

namespace Tests\Unit\Tenancy;

use App\Models\School;
use App\Support\Tenancy\TenantStoragePath;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantStoragePathTest extends TestCase
{
    private function fakeSchool(string $id): School
    {
        $school = new School;
        $school->id = $id;

        return $school;
    }

    #[Test]
    public function it_builds_a_school_prefixed_path(): void
    {
        $school = $this->fakeSchool('school-a');

        $this->assertSame(
            'schools/school-a/admissions/2026/document.pdf',
            TenantStoragePath::for($school, 'admissions/2026/document.pdf'),
        );
    }

    #[Test]
    public function two_schools_with_the_same_filename_get_isolated_paths(): void
    {
        $schoolA = $this->fakeSchool('school-a');
        $schoolB = $this->fakeSchool('school-b');

        $pathA = TenantStoragePath::for($schoolA, 'photo.jpg');
        $pathB = TenantStoragePath::for($schoolB, 'photo.jpg');

        $this->assertNotSame($pathA, $pathB);
        $this->assertStringStartsWith('schools/school-a/', $pathA);
        $this->assertStringStartsWith('schools/school-b/', $pathB);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function traversalAttempts(): iterable
    {
        yield 'parent traversal' => ['../etc/passwd'];
        yield 'nested parent traversal' => ['photos/../../secrets/key'];
        yield 'bare dot segment' => ['./file'];
        yield 'leading slash absolute-looking' => ['/etc/passwd'];
        yield 'empty' => [''];
        yield 'double slash empty segment' => ['a//b'];
        yield 'url-encoded traversal' => ['a%2F..%2F..%2Fsecret'];
    }

    #[Test]
    #[DataProvider('traversalAttempts')]
    public function it_rejects_traversal_and_malformed_paths(string $maliciousPath): void
    {
        $this->expectException(InvalidArgumentException::class);

        TenantStoragePath::for($this->fakeSchool('school-a'), $maliciousPath);
    }
}
