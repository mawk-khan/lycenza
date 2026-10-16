<?php

namespace Tests\Feature\App;

use App\Models\School;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesFinanceFixtures;
use Tests\Concerns\CreatesTenancyFixtures;
use Tests\TestCase;

/**
 * UAT-C4 (UAT-UX-01, UAT-FIN-23, UAT-UX-02): creating a Section, a fee head
 * or a fee structure says so once through the shared banner (escaped text,
 * never repeated on the next page, validation errors unchanged); every
 * School page carries the School's timezone for showing stored instants.
 */
class UxFeedbackTest extends TestCase
{
    use CreatesFinanceFixtures, CreatesTenancyFixtures;

    private function enter(School $school, array $capabilities): User
    {
        $user = $this->createUserWithCapabilities($school, $capabilities);
        $this->actingAs($user)->post("/app/schools/{$school->id}/activate");

        return $user;
    }

    private function flashOn(string $url): ?string
    {
        return $this->get($url)->assertOk()->viewData('page')['props']['flash']['success'];
    }

    #[Test]
    public function creating_a_section_confirms_once(): void
    {
        $school = $this->createSchool();
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $campus = $this->createCampus($school);
        $grade = $this->createGradeLevel($school);
        $this->enter($school, ['academics.structure.view', 'academics.structure.manage']);

        $response = $this->post("/app/school-setup/academic-years/{$year->id}/sections", [
            'campus_id' => $campus->id, 'grade_level_id' => $grade->id, 'name' => 'C', 'code' => 'C',
        ])->assertRedirect()->assertSessionHas('flash', 'Section created.');

        $url = $response->headers->get('Location');
        $this->assertSame('Section created.', $this->flashOn($url), 'Shown on the page the form returns to.');
        $this->assertNull($this->flashOn($url), 'Never repeated on the next page.');

        // A refusal stays a form error, with no success message.
        $this->post("/app/school-setup/academic-years/{$year->id}/sections", [
            'campus_id' => $campus->id, 'grade_level_id' => $grade->id, 'name' => 'C', 'code' => 'C',
        ])->assertSessionHasErrors()->assertSessionMissing('flash');
    }

    #[Test]
    public function fee_setup_actions_confirm_and_a_refusal_does_not(): void
    {
        $school = $this->createSchool();
        $receivable = $this->createLedgerAccount($school, ['type' => 'asset', 'status' => 'active']);
        $revenue = $this->createLedgerAccount($school, ['type' => 'income', 'status' => 'active']);
        $year = $this->createAcademicYear($school, ['status' => 'active']);
        $grade = $this->createGradeLevel($school);
        $this->enter($school, ['finance.fee_structures.view', 'finance.fee_structures.manage']);

        $this->post('/app/finance/fee-setup/fee-heads', [
            'code' => 'TUI', 'name' => '<b>Tuition</b>', 'receivable_ledger_account_id' => $receivable->id, 'revenue_ledger_account_id' => $revenue->id,
        ])->assertRedirect('/app/finance/fee-setup')->assertSessionHas('flash', 'Fee head created.');

        $this->post('/app/finance/fee-setup/structures', [
            'academic_year_id' => $year->id, 'grade_level_id' => $grade->id, 'code' => 'G1', 'name' => 'Grade fees',
        ])->assertRedirect()->assertSessionHas('flash', 'Draft fee structure created.');

        $this->post('/app/finance/fee-setup/fee-heads', [
            'code' => 'BAD', 'name' => 'Bad', 'receivable_ledger_account_id' => $revenue->id, 'revenue_ledger_account_id' => $receivable->id,
        ])->assertSessionHasErrors()->assertSessionMissing('flash');
    }

    #[Test]
    public function school_pages_carry_the_school_timezone_and_account_pages_none(): void
    {
        $school = $this->createSchool(['timezone' => 'Asia/Kolkata']);
        $user = $this->enter($school, ['students.view']);

        $this->assertSame('Asia/Kolkata', $this->get('/app/students')->viewData('page')['props']['schoolTimezone']);

        $loner = $this->createUser();
        $this->actingAs($loner);
        $this->assertNull($this->get('/app/account/security')->viewData('page')['props']['schoolTimezone']);
        $this->assertNotNull($user);
    }
}
