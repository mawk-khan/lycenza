<?php

namespace Tests\Feature\Guardians;

use App\Domain\Guardians\Application\Exceptions\GuardianSelfAdministrationException;
use App\Domain\Guardians\Application\StudentGuardianRelationshipService;
use App\Domain\Guardians\Application\StudentGuardianRelationshipVersion;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Guardians\Infrastructure\RelationshipType;
use App\Domain\Guardians\Infrastructure\StudentGuardianRelationship;
use App\Domain\Identity\Application\AccountLinkService;
use App\Models\School;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.8A-R1 (ADR 0076 §59), Rule B: an administrator never links, unlinks or
 * changes a Student relationship of a Guardian identity linked to their own
 * account -- active or revoked link -- through the service, the JSON API or
 * the browser. Another authorized administrator can, and an unrelated
 * Guardian stays administrable. Before R1 the administrator could unlink
 * their own child's relationship and relink it (the G-7 bypass).
 */
class GuardianSelfAdministrationTest extends TestCase
{
    use CreatesTenancyFixtures;

    private function service(): StudentGuardianRelationshipService
    {
        return app(StudentGuardianRelationshipService::class);
    }

    private function membership(User $user, School $school): SchoolMembership
    {
        return app(TenantContext::class)->withSchool($school, fn () => SchoolMembership::query()->where('user_id', $user->id)->where('school_id', $school->id)->firstOrFail());
    }

    /** Links $user's account to $guardian (the Guardian identity becomes theirs). */
    private function ownGuardian(User $user, School $school, Guardian $guardian, User $linker): void
    {
        app(TenantContext::class)->withSchool($school, fn () => app(AccountLinkService::class)->linkGuardian($school, $guardian, $this->membership($user, $school), $linker));
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        [$admin, $school] = $this->createSchoolAdmin('school_admin');
        $other = $this->createUser();
        $this->assignSchoolRole($this->createMembership($other, $school), 'school_admin');
        $student = $this->createStudent($school);
        $own = $this->createGuardian($school);
        $this->ownGuardian($admin, $school, $own, $other);

        return ['school' => $school, 'admin' => $admin, 'other' => $other, 'student' => $student, 'own' => $own,
            'relationship' => $this->createStudentGuardianRelationship($student, $own), 'unrelated' => $this->createGuardian($school)];
    }

    private function assertRefused(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Administering one\'s own Guardian identity must be refused.');
        } catch (GuardianSelfAdministrationException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('GUARDIAN_SELF_ADMINISTRATION', $e->errorCode());
        }
    }

    private function relationshipExists(array $w, StudentGuardianRelationship $r): bool
    {
        return app(TenantContext::class)->withSchool($w['school'], fn () => StudentGuardianRelationship::query()->whereKey($r->id)->exists());
    }

    #[Test]
    public function the_administrator_cannot_link_unlink_or_change_their_own_guardian_identity(): void
    {
        $w = $this->world();
        $second = $this->createStudent($w['school']);

        $this->assertRefused(fn () => $this->service()->unlink($w['relationship'], $w['admin']));
        $this->assertRefused(fn () => $this->service()->link($second, $w['own'], RelationshipType::Mother, [], $w['admin']));
        $this->assertRefused(fn () => $this->service()->update($w['relationship'], ['is_legal_guardian' => true], StudentGuardianRelationshipVersion::of($w['relationship']), $w['admin']));
        $this->assertRefused(fn () => $this->service()->setPrimary($w['relationship'], $w['admin']));

        $this->assertTrue($this->relationshipExists($w, $w['relationship']), 'the relationship is untouched');
        $this->assertFalse(app(TenantContext::class)->withSchool($w['school'], fn () => StudentGuardianRelationship::query()->where('student_id', $second->id)->exists()));
    }

    #[Test]
    public function another_administrator_and_an_unrelated_guardian_are_unaffected(): void
    {
        $w = $this->world();

        $this->service()->unlink($w['relationship'], $w['other']);
        $this->assertFalse($this->relationshipExists($w, $w['relationship']), 'another administrator may unlink');
        $this->service()->link($w['student'], $w['own'], RelationshipType::Father, [], $w['other']);

        $mine = $this->service()->link($w['student'], $w['unrelated'], RelationshipType::Mother, [], $w['admin']);
        $this->service()->unlink($mine, $w['admin']);
        $this->assertFalse($this->relationshipExists($w, $mine), 'an unrelated Guardian stays administrable by the administrator');
    }

    #[Test]
    public function a_revoked_account_link_still_makes_the_guardian_their_own(): void
    {
        $w = $this->world();
        app(TenantContext::class)->withSchool($w['school'], fn () => app(AccountLinkService::class)->unlinkGuardian($w['school'], $w['own'], $w['other']));

        $this->assertRefused(fn () => $this->service()->unlink($w['relationship'], $w['admin']));
        $this->assertTrue($this->relationshipExists($w, $w['relationship']));
    }

    #[Test]
    public function another_schools_guardian_link_never_counts_here(): void
    {
        $w = $this->world();
        [$otherAdmin, $otherSchool] = $this->createSchoolAdmin('school_admin');
        $this->assignSchoolRole($this->createMembership($w['admin'], $otherSchool), 'school_admin');
        $guardianThere = $this->createGuardian($otherSchool);
        $relationshipThere = $this->createStudentGuardianRelationship($this->createStudent($otherSchool), $guardianThere);

        // The administrator's own Guardian identity lives in the first School only.
        $this->service()->unlink($relationshipThere, $w['admin']);
        $this->assertFalse(app(TenantContext::class)->withSchool($otherSchool, fn () => StudentGuardianRelationship::query()->whereKey($relationshipThere->id)->exists()));
        $this->assertNotNull($otherAdmin->id);
    }

    #[Test]
    public function the_json_api_and_the_browser_refuse_generically(): void
    {
        $w = $this->world();
        $token = $w['admin']->createToken('test-device')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/schools/{$w['school']->id}/student-guardian-relationships/{$w['relationship']->id}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'GUARDIAN_SELF_ADMINISTRATION');
        foreach ([$w['own']->id, $w['student']->id, 'account', 'link'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $response->json('error.message'));
        }
        $this->withHeader('Authorization', 'Bearer '.$token)->withHeader('Idempotency-Key', 'self-link-1')
            ->postJson("/api/v1/schools/{$w['school']->id}/students/{$this->createStudent($w['school'])->id}/guardians", ['guardian_id' => $w['own']->id, 'relationship_type' => 'mother'])
            ->assertForbidden();

        $this->flushHeaders();
        $this->actingAs($w['admin'])->post("/app/schools/{$w['school']->id}/activate");
        // SIS 2.2: still a 403, now saying why (the same fixed text as the API, ExplainedRefusal).
        $this->delete("/app/relationships/{$w['relationship']->id}")->assertForbidden()->assertSee('Another administrator must make this change.');
        $this->assertTrue($this->relationshipExists($w, $w['relationship']));
    }

    #[Test]
    public function every_application_caller_names_the_acting_user(): void
    {
        $checked = 0;
        foreach (Finder::create()->files()->name('*.php')->in(app_path())->notPath('Domain/Guardians/Application/StudentGuardianRelationshipService.php') as $file) {
            $source = $file->getContents();
            if (! str_contains($source, 'StudentGuardianRelationshipService')) {
                continue;
            }
            // Every receiver of the service in this file: typed variables/properties, or an inline container resolution.
            preg_match_all('/StudentGuardianRelationshipService\s+\$(\w+)/', $source, $names);
            $receivers = array_map(fn (string $n) => '\$(?:this->)?'.$n, array_unique($names[1]));
            $receivers[] = 'app\(StudentGuardianRelationshipService::class\)';
            preg_match_all('/(?:'.implode('|', $receivers).')->(link|unlink|update|setPrimary)\((.*?)\);/s', $source, $calls, PREG_SET_ORDER);
            foreach ($calls as [, $method, $arguments]) {
                $checked++;
                $this->assertMatchesRegularExpression('/(\$actor|->actor\(\)|->user\(\))\s*,?$/', trim($arguments), "{$file->getRelativePathname()}: {$method}() must name the acting User (Rule B needs it)");
            }
        }
        $this->assertGreaterThanOrEqual(8, $checked, 'every web, API and Admissions caller was found');
    }
}
