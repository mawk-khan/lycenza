<?php

namespace Tests\Feature\Communications\Concerns;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\Audience\FeeReminder\FeeReminderSelection;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Fees\Infrastructure\Charge;
use App\Domain\Guardians\Infrastructure\Guardian;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Payments\Application\Collections\OutstandingFeesReadService;
use App\Domain\Students\Infrastructure\Student;
use App\Models\Role;
use App\Models\SchoolMembership;
use App\Models\User;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Testing\LocalCatalogueFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesGuardianPortalFixtures;
use Tests\Concerns\FakesEmail;
use Tests\Feature\Fees\Concerns\CreatesLateFeeFixtures;

/**
 * ORG.9D-2 (ADR 0076 §74): a fee-reminder world built through the real
 * services -- Charges assessed by ChargeService, payments by the manual
 * payment path, concessions approved by FeeConcessionService, Guardian
 * accounts linked by AccountLinkService, the reminder drafted, approved and
 * published through AnnouncementService / CommunicationApprovalService.
 *
 * The initiator holds communications.announce through one role and the two
 * Finance read keys through ANOTHER (`financeRole`), so a test can take
 * either away. The approver holds communications.approve only.
 */
trait CreatesFeeReminderFixtures
{
    use CreatesGuardianPortalFixtures, CreatesLateFeeFixtures, FakesEmail;

    /** @return array<string, mixed> the concession world (its Student owes 1000.00) plus initiator / approver / roles */
    protected function reminderWorld(?array $w = null): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-01T06:00:00Z'));
        Config::set('communications.channels.email.enabled', true);
        $this->fakeEmail();

        $w ??= $this->concessionWorld();
        $w['commsRole'] = $this->createFixtureRole(['communications.view', 'communications.announce']);
        $w['financeRole'] = $this->createFixtureRole(['finance.charges.view', 'finance.payments.view']);
        $w['initiator'] = $this->createUser();
        $w['initiatorMembership'] = $this->createMembership($w['initiator'], $w['school']);
        LocalCatalogueFixtures::grantRole($w['initiatorMembership'], $w['commsRole']);
        LocalCatalogueFixtures::grantRole($w['initiatorMembership'], $w['financeRole']);
        $w['approver'] = $this->createUserWithCapabilities($w['school'], ['communications.view', 'communications.approve']);

        return $w;
    }

    /**
     * A current Guardian ACCOUNT of `$students`: a User with an active
     * membership, a Guardian persona, a legal-guardian relationship to each
     * Student and the one active account link.
     *
     * @param  list<Student>  $students
     * @return array{user: User, membership: SchoolMembership, guardian: Guardian}
     */
    protected function guardianAccount(array $w, array $students, string $email, ?User $user = null, ?SchoolMembership $membership = null): array
    {
        $user ??= $this->createUser(['email' => $email]);
        $membership ??= $this->createMembership($user, $w['school']);
        $guardian = $this->guardianOf($w, $students);
        app(AccountLinkService::class)->linkGuardian($w['school'], $guardian, $membership, $this->portalAdmin($w['school']));

        return ['user' => $user, 'membership' => $membership, 'guardian' => $guardian];
    }

    /** @param  list<Student>  $students */
    protected function guardianOf(array $w, array $students, bool $legal = true): Guardian
    {
        $guardian = $this->createGuardian($w['school']);
        foreach ($students as $student) {
            $this->createStudentGuardianRelationship($student, $guardian, ['is_legal_guardian' => $legal]);
        }

        return $guardian;
    }

    protected function owing(array $w, string $amount, string $first, ?string $dueDate = null): Student
    {
        $student = $this->createStudent($w['school'], ['first_name' => $first, 'last_name' => 'Pupil']);
        $this->assessCharge($w['school'], $student, $w['year'], $w['receivable'], $w['revenue'], $amount, dueDate: $dueDate);

        return $student;
    }

    protected function draftReminder(array $w, ?FeeReminderSelection $selection = null, string $body = 'Please pay at the school office.', ?User $creator = null): CommunicationAnnouncement
    {
        return app(AnnouncementService::class)->createDraft(
            $w['school'], $creator ?? $w['initiator'], 'Term 1 fees', $body, CommunicationPriority::Normal, CommunicationAudienceType::FeeReminder,
            feeReminder: $selection ?? new FeeReminderSelection,
        );
    }

    protected function approveReminder(array $w, CommunicationAnnouncement $announcement): void
    {
        $approvals = app(CommunicationApprovalService::class);
        $approvals->approve($approvals->submit($announcement, $announcement->createdBy ?? $w['initiator']), $w['approver']);
    }

    protected function sendReminder(array $w, ?FeeReminderSelection $selection = null): CommunicationAnnouncement
    {
        $announcement = $this->draftReminder($w, $selection);
        $this->approveReminder($w, $announcement);

        return app(AnnouncementService::class)->publish($this->freshOf($w, $announcement), $w['initiator']);
    }

    /**
     * What each recipient's email says: userId => ["Name: amount", ...].
     *
     * @return array<string, list<string>>
     */
    protected function linesOf(array $w, CommunicationAnnouncement $announcement): array
    {
        return $this->inSchool($w['school'], fn () => DB::table('communication_fee_reminder_lines as l')
            ->join('communication_recipients as r', 'r.id', '=', 'l.recipient_id')
            ->where('r.message_id', $announcement->refresh()->message_id)
            ->orderBy('r.recipient_user_id')->orderBy('l.position')
            ->get(['r.recipient_user_id', 'l.student_name', 'l.outstanding_amount'])
            ->groupBy('recipient_user_id')
            ->map(fn ($lines) => $lines->map(fn ($l) => "{$l->student_name}: {$l->outstanding_amount}")->values()->all())
            ->all());
    }

    /** The Outstanding fees page's own balance per Student, for the initiator, right now. @return array<string, string> */
    protected function pageBalances(array $w, string $status = OutstandingFeesReadService::OUTSTANDING): array
    {
        $list = $this->inSchool($w['school'], fn () => app(OutstandingFeesReadService::class)->list($w['school'], $w['initiator'], $status));

        return collect($list->rows)->mapWithKeys(fn ($row) => [$row->studentId => $row->outstanding->amount()])->all();
    }

    protected function freshOf(array $w, CommunicationAnnouncement $announcement): CommunicationAnnouncement
    {
        return $this->inSchool($w['school'], fn () => $announcement->fresh());
    }

    protected function chargeFor(array $w, Student $student): Charge
    {
        return $this->inSchool($w['school'], fn () => Charge::query()->where('student_id', $student->id)->orderBy('created_at')->firstOrFail());
    }

    protected function revokeFinance(array $w, string $capability = 'finance.payments.view'): void
    {
        /** @var Role $role */
        $role = $w['financeRole'];
        LocalCatalogueFixtures::removeRoleCapability($role, $capability);
        app(CapabilityResolver::class)->forgetCache($w['initiator'], $w['school']);
    }
}
