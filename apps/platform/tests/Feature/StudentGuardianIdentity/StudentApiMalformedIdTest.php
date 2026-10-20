<?php

namespace Tests\Feature\StudentGuardianIdentity;

use App\Models\School;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.7F-1 (finding F-12, reproduced): a malformed Student, Enrollment or
 * Student-Guardian relationship id on the JSON API reached PostgreSQL as an
 * invalid-UUID query (SQLSTATE 22P02) -- a raw database error, never a
 * resource answer. It is now NOT FOUND before any query, exactly like a
 * well-formed unknown id (the HR API convention).
 */
class StudentApiMalformedIdTest extends TestCase
{
    use CreatesTenancyFixtures;

    /** @return array<string, array{0: string, 1: string}> */
    public static function routes(): array
    {
        return [
            'student detail' => ['GET', '/students/{id}'],
            'student update' => ['PATCH', '/students/{id}'],
            'student guardians' => ['GET', '/students/{id}/guardians'],
            'enrollment history' => ['GET', '/students/{id}/enrollments'],
            'current placement' => ['GET', '/students/{id}/enrollments/current'],
            'enrollment detail' => ['GET', '/enrollments/{id}'],
            'relationship update' => ['PATCH', '/student-guardian-relationships/{id}'],
            'relationship unlink' => ['DELETE', '/student-guardian-relationships/{id}'],
            'guardian detail' => ['GET', '/guardians/{id}'],
            'guardian update' => ['PATCH', '/guardians/{id}'],
            'subject-offering roster' => ['GET', '/subject-offerings/{id}/roster'],
            'rollover detail' => ['GET', '/enrollment-rollovers/{id}'],
            'rollover items' => ['GET', '/enrollment-rollovers/{id}/items'],
        ];
    }

    #[Test]
    #[DataProvider('routes')]
    public function a_malformed_id_is_not_found_like_an_unknown_one(string $method, string $path): void
    {
        [$user, $school] = $this->createSchoolAdmin('school_admin');

        foreach (['not-a-uuid', "1' OR '1'='1", '00000000-0000-0000-0000-00000000000Z'] as $malformed) {
            $this->send($user, $school, $method, str_replace('{id}', rawurlencode($malformed), $path))->assertNotFound()->assertJsonMissingPath('error.exception');
        }
        $this->send($user, $school, $method, str_replace('{id}', '01a15109-0000-7000-8000-000000000000', $path))->assertNotFound();
    }

    private function send(User $user, School $school, string $method, string $path): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$user->createToken('t')->plainTextToken)
            ->json($method, "/api/v1/schools/{$school->id}{$path}", $method === 'GET' || $method === 'DELETE' ? [] : ['first_name' => 'X']);
    }

    protected function tearDown(): void
    {
        app('auth')->forgetGuards();
        parent::tearDown();
    }
}
