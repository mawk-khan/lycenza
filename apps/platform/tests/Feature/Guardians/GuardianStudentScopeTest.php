<?php

namespace Tests\Feature\Guardians;

use App\Domain\Guardians\Application\GuardianStudentScope;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * ORG.9D-2 (ADR 0076 §74): GuardianStudentScope's set form
 * (eligibleGuardiansByStudent(), bounded reads for performance) answers
 * exactly what its single predicate (eligibleStudentIdsQuery(), the POR
 * portal rule) answers, Guardian by Guardian -- legal guardian only, active
 * Student, active Guardian, this School only.
 */
class GuardianStudentScopeTest extends TestCase
{
    use CreatesTenancyFixtures;

    #[Test]
    public function the_set_form_equals_the_portal_predicate_for_every_case(): void
    {
        $school = $this->createSchool();
        $active = $this->createStudent($school);
        $sibling = $this->createStudent($school);
        $leaver = $this->createStudent($school);
        $this->forceStudentStatusOf($school, $leaver->id, 'inactive');

        $legal = $this->createGuardian($school);
        $contactOnly = $this->createGuardian($school);
        $ended = $this->createGuardian($school, ['status' => 'inactive']);
        foreach ([$active, $sibling, $leaver] as $student) {
            $this->createStudentGuardianRelationship($student, $legal, ['is_legal_guardian' => true]);
        }
        $this->createStudentGuardianRelationship($active, $contactOnly, ['is_legal_guardian' => false, 'is_emergency_contact' => true]);
        $this->createStudentGuardianRelationship($active, $ended, ['is_legal_guardian' => true]);

        $other = $this->createSchool();
        $otherStudent = $this->createStudent($other);
        $this->createStudentGuardianRelationship($otherStudent, $this->createGuardian($other), ['is_legal_guardian' => true]);

        $scope = app(GuardianStudentScope::class);
        $set = $scope->eligibleGuardiansByStudent($school, [$active->id, $sibling->id, $leaver->id, $otherStudent->id]);

        $this->assertSame([$active->id => [$legal->id], $sibling->id => [$legal->id]], $set);
        foreach ([$legal, $contactOnly, $ended] as $guardian) {
            $viaPredicate = $this->eligible($school, $guardian->id);
            $viaSet = array_keys(array_filter($set, fn (array $guardians) => in_array($guardian->id, $guardians, true)));
            sort($viaPredicate);
            sort($viaSet);
            $this->assertSame($viaPredicate, $viaSet, "Guardian {$guardian->id}: the set form and the predicate agree.");
        }
        $this->assertSame([], $scope->eligibleGuardiansByStudent($school, []));
    }

    /** @return list<string> */
    private function eligible(School $school, string $guardianId): array
    {
        return app(TenantContext::class)->withSchool($school, fn () => app(GuardianStudentScope::class)
            ->eligibleStudentIdsQuery($school, $guardianId)->pluck('sgr.student_id')->map(fn ($id) => (string) $id)->all());
    }
}
