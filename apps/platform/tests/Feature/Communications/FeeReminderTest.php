<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\AnnouncementService;
use App\Domain\Communications\Application\Approval\CommunicationApprovalService;
use App\Domain\Communications\Application\Audience\FeeReminder\FeeReminderSelection;
use App\Domain\Communications\Application\Channels\EmailChannelDriver;
use App\Domain\Communications\Application\CommunicationDeliveryFactory;
use App\Domain\Communications\Application\CommunicationInboxReadModel;
use App\Domain\Communications\Application\Exceptions\ApprovalRequiredException;
use App\Domain\Communications\Application\Exceptions\EmptyAudienceException;
use App\Domain\Communications\Application\Exceptions\FeeReminderAuthorityException;
use App\Domain\Communications\Application\Exceptions\FeeReminderNotAllowedException;
use App\Domain\Communications\Application\Exceptions\SelfApprovalNotAllowedException;
use App\Domain\Communications\Application\Policy\CommunicationConsentService;
use App\Domain\Communications\Application\Policy\CommunicationDomainPreferenceService;
use App\Domain\Communications\Domain\CommunicationAudienceType;
use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Domain\CommunicationDispatchMode;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Domain\CommunicationRequirement;
use App\Domain\Identity\Application\AccountLinkService;
use App\Domain\Payments\Application\PaymentCorrectionData;
use App\Domain\Payments\Application\PaymentCorrectionService;
use App\Domain\Payments\Domain\ManualPaymentMethod;
use App\Domain\Payments\Infrastructure\PaymentCorrection;
use App\Domain\Students\Infrastructure\Student;
use App\Jobs\ProcessCommunicationDeliveryJob;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Email\Providers\OutboundEmail;
use App\Support\Money\Money;
use App\Support\Testing\LocalCatalogueFixtures;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Communications\Concerns\CreatesFeeReminderFixtures;
use Tests\TestCase;

/**
 * ORG.9D-2 (ADR 0076 §74, PV1-11): the fee-due reminder email.
 *
 * Finance truth -> recipients -> approval -> email: each Student's amount is
 * the Outstanding fees page's balance for the initiator at publish; each
 * current Guardian ACCOUNT gets one email listing only its own selected
 * Students; approval always applies and binds the message and the population
 * definition, never the amounts; the published lines are immutable and the
 * email is rendered from them alone.
 */
class FeeReminderTest extends TestCase
{
    use CreatesFeeReminderFixtures;

    private function service(): AnnouncementService
    {
        return app(AnnouncementService::class);
    }

    private function correct(array $w, string $paymentId, string $kind, array $extra = []): void
    {
        app(PaymentCorrectionService::class)->correct($w['school'], new PaymentCorrectionData(
            paymentId: $paymentId,
            kind: $kind,
            reason: 'Entered against the wrong charge',
            idempotencyKey: (string) Str::uuid(),
            chargeId: $extra['charge'] ?? null,
            amount: isset($extra['amount']) ? Money::of($extra['amount'], 'INR') : null,
            accountId: $w['settlement']->id,
            method: $extra['method'] ?? null,
            refundedOn: $extra['on'] ?? null,
        ), $w['recorder']);
        $this->inSchool($w['school'], function () {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::statement('SET CONSTRAINTS ALL DEFERRED');
        });
    }

    #[Test]
    public function every_amount_is_the_outstanding_pages_own_balance_after_concessions_payments_corrections_and_cancellations(): void
    {
        $w = $this->reminderWorld(); // Student owes 1000.00
        $this->concessions()->approve($w['school'], $this->requestTargeted($w, '200.00')->id, $w['checker']);
        $this->pay($w, '300.00'); // 1000 - 200 - 300 = 500

        $paidUp = $this->owing($w, '400.00', 'Paid');
        $this->pay($w, '400.00', null, $this->chargeFor($w, $paidUp));

        $cancelled = $this->createStudent($w['school'], ['first_name' => 'Cancelled', 'last_name' => 'Pupil']);
        $charge = $this->assessCharge($w['school'], $cancelled, $w['year'], $w['receivable'], $w['revenue'], '150.00');
        $this->cancelChargeAs($w['school'], $charge->id);

        $reversed = $this->owing($w, '600.00', 'Reversed');
        $reversedCharge = $this->chargeFor($w, $reversed);
        $this->correct($w, $this->pay($w, '600.00', null, $reversedCharge)->paymentId, PaymentCorrection::REVERSAL); // owes 600 again

        $refunded = $this->owing($w, '500.00', 'Refunded');
        $refundedCharge = $this->chargeFor($w, $refunded);
        $this->correct($w, $this->pay($w, '300.00', null, $refundedCharge)->paymentId, PaymentCorrection::REFUND, ['charge' => $refundedCharge->id, 'amount' => '100.00', 'method' => ManualPaymentMethod::Cash, 'on' => '2026-07-01']); // 500 - 200 = 300

        $parent = $this->guardianAccount($w, [$w['student'], $paidUp, $cancelled, $reversed, $refunded], 'parent@school-os.test');

        $published = $this->sendReminder($w);

        $page = $this->pageBalances($w);
        $this->assertSame(['500.00', '600.00', '300.00'], [$page[$w['student']->id], $page[$reversed->id], $page[$refunded->id]]);
        $this->assertArrayNotHasKey($paidUp->id, $page);
        $this->assertArrayNotHasKey($cancelled->id, $page);

        $name = fn ($s) => trim("{$s->first_name} {$s->last_name}");
        $lines = $this->linesOf($w, $published)[$parent['user']->id];
        $this->assertEqualsCanonicalizing([
            "{$name($w['student'])}: 500.00",
            "{$name($refunded)}: 300.00",
            "{$name($reversed)}: 600.00",
        ], $lines, 'Only strictly positive balances, each equal to the page; the paid-up and cancelled-only Students are absent.');
        $this->assertEmailAcceptedCount(1);
    }

    #[Test]
    public function a_late_fee_charge_is_reminded_exactly_as_the_page_reports_it(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-01T06:00:00Z'));
        $w = $this->lateFeeWorld(); // sourceStudent owes the T1 5000.00 charge, due 2026-06-10
        $this->travelTo(CarbonImmutable::parse('2026-06-11T06:00:00Z'));
        $this->executedLateRun($w, $this->activeRule($w));
        $this->assertCount(1, $this->lateFees($w), 'The late fee is its own (run-owned) Charge.');

        $w = $this->reminderWorld($w);
        $student = $this->inSchool($w['school'], fn () => Student::query()->findOrFail($w['sourceStudent']));
        $parent = $this->guardianAccount($w, [$student], 'late@school-os.test');

        $published = $this->sendReminder($w);

        $page = $this->pageBalances($w);
        $this->assertSame([trim("{$student->first_name} {$student->last_name}").': '.$page[$student->id]], $this->linesOf($w, $published)[$parent['user']->id]);
        $this->assertSame(1, bccomp($page[$student->id], '5000.00', 2), 'The late fee is part of the reminded balance.');
    }

    #[Test]
    public function each_current_guardian_account_gets_one_email_with_only_its_own_selected_students(): void
    {
        $w = $this->reminderWorld(); // the world's own Student owes 1000.00 and has no Guardian account
        $s1 = $this->owing($w, '100.00', 'Asha');
        $s2 = $this->owing($w, '200.00', 'Bilal');
        $s3 = $this->owing($w, '300.00', 'Chen');

        $both = $this->guardianAccount($w, [$s1, $s2], 'both@school-os.test');
        $secondOfS1 = $this->guardianAccount($w, [$s1], 'second@school-os.test');
        $this->guardianOf($w, [$s3]); // a Guardian with no account
        $notLegal = $this->guardianAccount($w, [], 'contact@school-os.test');
        $this->createStudentGuardianRelationship($s3, $notLegal['guardian'], ['is_legal_guardian' => false, 'is_emergency_contact' => true]);

        // A Student's own account is never a recipient.
        $studentUser = $this->createUser(['email' => 'student@school-os.test']);
        app(AccountLinkService::class)->linkStudent($w['school'], $s1, $this->createMembership($studentUser, $w['school']), $this->portalAdmin($w['school']));

        $published = $this->sendReminder($w);

        $lines = $this->linesOf($w, $published);
        $this->assertEqualsCanonicalizing([$both['user']->id, $secondOfS1['user']->id], array_keys($lines));
        $this->assertSame(['Asha Pupil: 100.00', 'Bilal Pupil: 200.00'], $lines[$both['user']->id], 'One email listing both of their Students.');
        $this->assertSame(['Asha Pupil: 100.00'], $lines[$secondOfS1['user']->id]);
        $this->assertEmailAcceptedCount(2);
        $this->assertEmailAcceptedTo('both@school-os.test');
        $this->assertEmailNotAcceptedTo('student@school-os.test');
        $this->assertEmailNotAcceptedTo('contact@school-os.test');

        $audit = $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->where('event_type', 'announcement.published')->where('subject_id', $published->id)->value('metadata'));
        $this->assertSame(['audienceType' => 'fee_reminder', 'resolvedCount' => 2, 'feeReminderStudents' => 4, 'feeReminderStudentsWithoutRecipient' => 2], json_decode($audit, true), 'Counts only: no Student, amount, Guardian or address.');
    }

    #[Test]
    public function former_disabled_suspended_and_other_school_guardians_are_never_recipients(): void
    {
        $w = $this->reminderWorld();
        $current = $this->guardianAccount($w, [$w['student']], 'current@school-os.test');
        $revoked = $this->guardianAccount($w, [$w['student']], 'revoked@school-os.test');
        app(AccountLinkService::class)->unlinkGuardian($w['school'], $revoked['guardian'], $this->portalAdmin($w['school']));
        $disabled = $this->guardianAccount($w, [$w['student']], 'disabled@school-os.test');
        $disabled['user']->forceFill(['is_disabled' => true])->save();
        $suspended = $this->guardianAccount($w, [$w['student']], 'suspended@school-os.test');
        $this->inSchool($w['school'], fn () => $suspended['membership']->forceFill(['status' => 'suspended'])->save());
        $ended = $this->guardianAccount($w, [$w['student']], 'ended@school-os.test');
        $this->inSchool($w['school'], fn () => $ended['guardian']->forceFill(['status' => 'inactive'])->save());

        // The same person as a Guardian in ANOTHER School never receives this School's reminder through that link.
        $other = $this->createSchool();
        $elsewhere = $this->createUser(['email' => 'elsewhere@school-os.test']);
        $otherStudent = $this->createStudent($other);
        $otherGuardian = $this->createGuardian($other);
        $this->createStudentGuardianRelationship($otherStudent, $otherGuardian, ['is_legal_guardian' => true]);
        app(AccountLinkService::class)->linkGuardian($other, $otherGuardian, $this->createMembership($elsewhere, $other), $this->portalAdmin($other));
        $this->createMembership($elsewhere, $w['school']);

        $published = $this->sendReminder($w);

        $this->assertSame([$current['user']->id], array_keys($this->linesOf($w, $published)));
        $this->assertEmailAcceptedCount(1);
        $this->assertEmailAcceptedTo('current@school-os.test');
    }

    #[Test]
    public function a_staff_member_who_is_a_guardian_gets_it_once_by_email_and_never_in_a_staff_inbox(): void
    {
        $w = $this->reminderWorld();
        $teacher = $this->createUser(['email' => 'teacher@school-os.test']);
        $membership = $this->createMembership($teacher, $w['school']);
        $this->assignSchoolRole($membership, 'teacher');
        $this->guardianAccount($w, [$w['student']], 'teacher@school-os.test', $teacher, $membership);

        $published = $this->sendReminder($w);

        $this->assertSame([$teacher->id], array_keys($this->linesOf($w, $published)));
        $this->assertEmailAcceptedTo('teacher@school-os.test');
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('communication_deliveries')->where('channel', 'in_app')->count()), 'Email only: no in-app delivery.');
        $inbox = app(CommunicationInboxReadModel::class)->inbox($w['school'], $teacher);
        $this->assertFalse($inbox->contains(fn ($item) => ($item->id ?? null) === $published->id), 'Never surfaced in a staff in-app inbox.');
        $this->assertSame(0, app(CommunicationInboxReadModel::class)->unreadAnnouncementCount($w['school'], $teacher));
    }

    #[Test]
    public function preview_is_advisory_and_writes_nothing(): void
    {
        $w = $this->reminderWorld();
        $s1 = $this->owing($w, '250.50', 'Asha');
        $this->guardianAccount($w, [$w['student'], $s1], 'parent@school-os.test');
        $this->guardianAccount($w, [$s1], 'other@school-os.test');
        $this->owing($w, '75.00', 'NoAccount');
        $draft = $this->draftReminder($w);

        $before = $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->where('event_type', 'fee_outstanding_list.viewed')->count());
        $preview = $this->service()->previewAudience($draft);

        $this->assertSame(2, $preview->count());
        $this->assertSame(3, $preview->feeReminder->studentCount);
        $this->assertSame(1, $preview->feeReminder->studentsWithoutRecipient);
        $this->assertSame('1325.50', $preview->feeReminder->totalOutstanding);
        $this->assertSame('INR', $preview->feeReminder->currency);
        foreach (['communication_announcement_recipients', 'communication_recipients', 'communication_deliveries', 'communication_fee_reminder_lines', 'email_messages'] as $table) {
            $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table($table)->count()), "Preview reserves nothing in {$table}.");
        }
        $this->assertSame('draft', $this->freshOf($w, $draft)->status);
        $this->assertSame($before + 1, $this->inSchool($w['school'], fn () => DB::table('school_audit_events')->where('event_type', 'fee_outstanding_list.viewed')->count()), 'One Finance read audit per preview, as the page.');
    }

    #[Test]
    public function it_always_needs_approval_by_someone_else_and_is_email_only_manual_and_never_emergency(): void
    {
        $w = $this->reminderWorld();
        $this->guardianAccount($w, [$w['student']], 'parent@school-os.test');
        $draft = $this->draftReminder($w);

        $this->assertSame(['email'], $this->inSchool($w['school'], fn () => DB::table('communication_announcement_channels')->where('announcement_id', $draft->id)->pluck('channel')->all()));
        $this->assertSame(['fee_reminder'], app(CommunicationApprovalService::class)->requirement($draft)->reasons, 'Required with no School approval policy at all.');
        $this->assertThrows(fn () => $this->service()->publish($draft, $w['initiator']), ApprovalRequiredException::class);
        $this->assertThrows(fn () => $this->service()->schedule($draft, $w['initiator'], now()->addDay()), FeeReminderNotAllowedException::class);
        $this->assertThrows(fn () => $this->service()->updateDraft($draft, $w['initiator'], requirement: CommunicationRequirement::Required, dispatchMode: CommunicationDispatchMode::Emergency, emergencyJustification: 'Now'), FeeReminderNotAllowedException::class);
        $this->assertThrows(fn () => $this->service()->createDraft($w['school'], $w['initiator'], 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::FeeReminder), FeeReminderNotAllowedException::class);

        $approvals = app(CommunicationApprovalService::class);
        $request = $approvals->submit($draft, $w['initiator']);
        $this->assertThrows(fn () => $approvals->approve($request, $w['initiator']), SelfApprovalNotAllowedException::class);
        $this->assertArrayHasKey('feeReminder', $request->snapshot);
        $this->assertSame((new FeeReminderSelection)->toArray(), $request->snapshot['feeReminder']);

        $approvals->reject($request, $w['approver'], 'Wrong term');
        $this->assertSame('rejected', $this->freshOf($w, $draft)->status);
        $this->service()->updateDraft($this->freshOf($w, $draft), $w['initiator'], body: 'Please pay by Friday.');
        $request = $approvals->submit($this->freshOf($w, $draft), $w['initiator']);
        $approvals->withdraw($this->freshOf($w, $draft), $w['initiator']);
        $this->assertSame('draft', $this->freshOf($w, $draft)->status);

        $approvals->approve($approvals->submit($this->freshOf($w, $draft), $w['initiator']), $w['approver']);
        $this->service()->publish($this->freshOf($w, $draft), $w['initiator']);
        $this->assertSame('published', $this->freshOf($w, $draft)->status);
        $this->assertSame(0, $this->inSchool($w['school'], fn () => DB::table('communication_deliveries')->where('channel', 'in_app')->count()));
    }

    #[Test]
    public function an_edit_after_approval_invalidates_it_but_a_balance_change_does_not(): void
    {
        $w = $this->reminderWorld();
        $parent = $this->guardianAccount($w, [$w['student']], 'parent@school-os.test');

        $edited = $this->draftReminder($w);
        $this->approveReminder($w, $edited);
        $this->service()->updateDraft($this->freshOf($w, $edited), $w['initiator'], body: 'Changed after approval');
        $this->assertSame('draft', $this->freshOf($w, $edited)->status, 'A material edit returns it to Draft.');
        $this->assertThrows(fn () => $this->service()->publish($this->freshOf($w, $edited), $w['initiator']), ApprovalRequiredException::class);

        $draft = $this->draftReminder($w);
        $this->approveReminder($w, $draft);
        $this->pay($w, '400.00'); // the approved operation is "send the CURRENT outstanding to this population"
        $this->concessions()->approve($w['school'], $this->requestTargeted($w, '100.00')->id, $w['checker']);
        $this->assertTrue(app(CommunicationApprovalService::class)->currentlyApprovedAndValid($this->freshOf($w, $draft)));

        $published = $this->service()->publish($this->freshOf($w, $draft), $w['initiator']);

        $lines = $this->linesOf($w, $published)[$parent['user']->id];
        $this->assertCount(1, $lines);
        $this->assertStringEndsWith(': 500.00', $lines[0], '1000 - 400 paid - 100 concession, read at publish.');
        $this->assertSame('500.00', $this->pageBalances($w)[$w['student']->id]);
    }

    #[Test]
    public function publish_re_reads_finance_and_guardians_and_refuses_an_audience_that_has_gone(): void
    {
        $w = $this->reminderWorld();
        $s1 = $this->owing($w, '100.00', 'Asha');
        $s2 = $this->owing($w, '200.00', 'Bilal');
        $s3 = $this->owing($w, '300.00', 'Chen');
        $g1 = $this->guardianAccount($w, [$s1], 'g1@school-os.test');
        $this->guardianAccount($w, [$s2], 'g2@school-os.test');
        $g3 = $this->guardianAccount($w, [$s3], 'g3@school-os.test');
        $draft = $this->draftReminder($w);
        $this->approveReminder($w, $draft);

        $this->pay($w, '100.00', null, $this->chargeFor($w, $s1)); // paid in full after approval
        app(AccountLinkService::class)->unlinkGuardian($w['school'], $g3['guardian'], $this->portalAdmin($w['school'])); // relationship's account revoked

        $published = $this->service()->publish($this->freshOf($w, $draft), $w['initiator']);

        $lines = $this->linesOf($w, $published);
        $this->assertSame(['Bilal Pupil: 200.00'], array_values($lines)[0]);
        $this->assertCount(1, $lines);
        $this->assertArrayNotHasKey($g1['user']->id, $lines);

        // Everyone paid between approval and publish: nothing misleading is published.
        $w2 = $this->reminderWorld();
        $this->guardianAccount($w2, [$w2['student']], 'parent2@school-os.test');
        $empty = $this->draftReminder($w2);
        $this->approveReminder($w2, $empty);
        $this->pay($w2, '1000.00');
        $this->assertThrows(fn () => $this->service()->publish($this->freshOf($w2, $empty), $w2['initiator']), EmptyAudienceException::class);
        $this->assertSame('approved', $this->freshOf($w2, $empty)->status);
        $this->assertNull($this->freshOf($w2, $empty)->message_id);
    }

    #[Test]
    public function the_initiators_current_finance_authority_and_identity_are_required_to_send_it(): void
    {
        $w = $this->reminderWorld();
        $this->guardianAccount($w, [$w['student']], 'parent@school-os.test');
        $draft = $this->draftReminder($w);
        $this->approveReminder($w, $draft);

        $manager = $this->createUserWithCapabilities($w['school'], ['communications.view', 'communications.announce', 'communications.manage', 'finance.charges.view', 'finance.payments.view']);
        $this->assertThrows(fn () => $this->service()->publish($this->freshOf($w, $draft), $manager), FeeReminderNotAllowedException::class);

        $this->revokeFinance($w);
        $this->assertThrows(fn () => $this->service()->publish($this->freshOf($w, $draft), $w['initiator']), FeeReminderAuthorityException::class);
        $this->assertSame('approved', $this->freshOf($w, $draft)->status);
        $this->assertNoEmailAccepted();

        // Without Finance visibility no reminder can be prepared at all.
        $this->assertThrows(fn () => $this->draftReminder($w), FeeReminderAuthorityException::class);
    }

    #[Test]
    public function the_email_is_rendered_from_the_published_snapshot_with_a_generic_subject_and_no_financial_history(): void
    {
        $w = $this->reminderWorld();
        $this->inSchool($w['school'], fn () => DB::table('students')->where('id', $w['student']->id)->update(['first_name' => '<b>Ann</b>', 'middle_name' => null, 'last_name' => 'Pupil', 'student_number' => 'ADM-7781']));
        $parent = $this->guardianAccount($w, [$w['student']], 'parent@school-os.test');
        $paymentId = $this->pay($w, '300.00')->paymentId;

        $published = $this->sendReminder($w);
        $email = $this->lastAcceptedEmail();

        $this->assertSame(EmailChannelDriver::FEE_REMINDER_SUBJECT, $email->subject);
        $this->assertStringNotContainsString('700', $email->subject);
        $this->assertStringContainsString('<b>Ann</b> Pupil: INR 700.00', $email->text);
        $this->assertStringContainsString('&lt;b&gt;Ann&lt;/b&gt; Pupil: INR 700.00', (string) $email->html, 'HTML-escaped.');
        $this->assertStringNotContainsString('<b>Ann</b>', (string) $email->html);
        $this->assertStringContainsString('Amounts outstanding as of 1 Jul 2026', $email->text);
        $this->assertStringContainsString('If you have already paid, please disregard this reminder.', $email->text);
        $this->assertStringContainsString('Please pay at the school office.', $email->text);
        foreach ([$email->subject, $email->text, (string) $email->html] as $part) {
            foreach (['ADM-7781', $w['charge']->id, $paymentId, $w['student']->id, 'concession', '300.00', '1000.00'] as $secret) {
                $this->assertStringNotContainsString($secret, $part);
            }
        }

        // A payment or a Guardian change after publish never rewrites what was published,
        // and a retry never sends twice.
        $this->pay($w, '700.00');
        app(AccountLinkService::class)->unlinkGuardian($w['school'], $parent['guardian'], $this->portalAdmin($w['school']));
        $this->assertSame(['<b>Ann</b> Pupil: 700.00'], $this->linesOf($w, $published)[$parent['user']->id]);
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('communication_fee_reminder_lines')->update(['outstanding_amount' => '1.00']))), QueryException::class);
        $delivery = $this->inSchool($w['school'], fn () => DB::table('communication_deliveries')->where('channel', 'email')->sole());
        ProcessCommunicationDeliveryJob::dispatchSync($w['school']->id, $delivery->id);
        $this->service()->publish($this->freshOf($w, $published), $w['initiator']);
        $this->assertEmailAcceptedCount(1);
        $this->assertEmailAccepted(fn (OutboundEmail $e) => $e->to === 'parent@school-os.test');
    }

    #[Test]
    public function a_recipient_without_lines_fails_closed_instead_of_a_generic_email(): void
    {
        $w = $this->reminderWorld();
        $this->guardianAccount($w, [$w['student']], 'parent@school-os.test');
        $published = $this->sendReminder($w);
        $this->fakeEmail();

        // A delivery planned for this message without its lines (never written by
        // publish) is refused by the driver rather than sent as a generic reminder.
        $stranger = $this->createUser(['email' => 'stranger@school-os.test']);
        $this->createMembership($stranger, $w['school']);
        $result = $this->inSchool($w['school'], function () use ($w, $published, $stranger) {
            $factory = app(CommunicationDeliveryFactory::class);
            $recipient = $factory->createRecipient($w['school']->id, $this->freshOf($w, $published)->message_id, $stranger->id);
            $delivery = $factory->createDelivery($recipient, CommunicationChannel::Email, ['email' => 'stranger@school-os.test'], null);

            return app(EmailChannelDriver::class)->send($delivery->fresh());
        });

        $this->assertSame('fee_reminder_content_missing', $result->failureCode);
        $this->assertNoEmailAccepted();

        // The runtime role can neither rewrite nor remove published lines.
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('communication_fee_reminder_lines')->delete())), QueryException::class);
    }

    #[Test]
    public function a_guardians_own_email_preference_and_consent_withdrawal_are_honoured_and_it_can_never_be_required(): void
    {
        $w = $this->reminderWorld();
        $s1 = $this->owing($w, '100.00', 'Asha');
        $s2 = $this->owing($w, '200.00', 'Bilal');
        $optedOut = $this->guardianAccount($w, [$w['student']], 'optedout@school-os.test');
        $withdrawn = $this->guardianAccount($w, [$s1], 'withdrawn@school-os.test');
        $this->guardianAccount($w, [$s2], 'reached@school-os.test');
        $admin = $this->portalAdmin($w['school']);
        app(CommunicationDomainPreferenceService::class)->setPreferenceForGuardian($w['school'], $optedOut['guardian'], $admin, CommunicationChannel::Email, false);
        app(CommunicationConsentService::class)->recordWithdrawalForGuardian($w['school'], $withdrawn['guardian'], $admin, CommunicationChannel::Email);

        $this->assertThrows(fn () => $this->service()->createDraft($w['school'], $w['initiator'], 'T', 'B', CommunicationPriority::Normal, CommunicationAudienceType::FeeReminder, requirement: CommunicationRequirement::Required, feeReminder: new FeeReminderSelection), FeeReminderNotAllowedException::class);
        $draft = $this->draftReminder($w);
        $this->assertThrows(fn () => $this->service()->updateDraft($this->freshOf($w, $draft), $w['initiator'], requirement: CommunicationRequirement::Required), FeeReminderNotAllowedException::class);
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('communication_announcements')->where('id', $draft->id)->update(['requirement' => 'required']))), QueryException::class);
        $this->assertThrows(fn () => $this->inSchool($w['school'], fn () => DB::transaction(fn () => DB::table('communication_announcement_fee_reminders')->where('announcement_id', $draft->id)->update(['balance_status' => 'overdue']))), QueryException::class);

        $this->approveReminder($w, $draft);
        $published = $this->service()->publish($this->freshOf($w, $draft), $w['initiator']);

        $this->assertSame([$this->createdUserIdFor('reached@school-os.test')], array_keys($this->linesOf($w, $published)));
        $this->assertEmailAcceptedCount(1);
        $this->assertEqualsCanonicalizing(['recipient_preference_disabled', 'consent_withdrawn'], $this->inSchool($w['school'], fn () => DB::table('communication_delivery_policy_decisions')->pluck('reason')->all()));
    }

    #[Test]
    public function its_preparer_never_approves_it_even_when_someone_else_submitted_it(): void
    {
        $w = $this->reminderWorld();
        $this->guardianAccount($w, [$w['student']], 'parent@school-os.test');
        LocalCatalogueFixtures::addRoleCapability($w['commsRole'], 'communications.approve');
        app(CapabilityResolver::class)->forgetCache($w['initiator'], $w['school']);
        $draft = $this->draftReminder($w);
        $manager = $this->createUserWithCapabilities($w['school'], ['communications.view', 'communications.announce', 'communications.manage']);

        $approvals = app(CommunicationApprovalService::class);
        $request = $approvals->submit($draft, $manager);
        $this->assertThrows(fn () => $approvals->approve($request, $w['initiator']), FeeReminderNotAllowedException::class);
        $this->assertSame('pending_approval', $this->freshOf($w, $draft)->status);
    }

    #[Test]
    public function a_class_reminder_never_widens_when_there_is_no_active_year(): void
    {
        $w = $this->reminderWorld();
        LocalCatalogueFixtures::addRoleCapability($w['financeRole'], 'enrollments.view');
        app(CapabilityResolver::class)->forgetCache($w['initiator'], $w['school']);
        $section = $this->createSection($w['year'], $w['campus'], $w['grade'], ['code' => 'S5A', 'status' => 'active']);
        $inClass = $this->owing($w, '100.00', 'Asha');
        $this->createStudentEnrollment($inClass, $section, ['starts_on' => '2026-06-01', 'status' => 'active']);
        $this->guardianAccount($w, [$inClass], 'inclass@school-os.test');
        $this->guardianAccount($w, [$w['student']], 'elsewhere@school-os.test'); // owes, not in the class

        $draft = $this->draftReminder($w, new FeeReminderSelection(sectionId: $section->id));
        $this->approveReminder($w, $draft);
        $this->inSchool($w['school'], fn () => DB::table('academic_years')->where('id', $w['year']->id)->update(['status' => 'closed']));

        $this->assertThrows(fn () => $this->service()->publish($this->freshOf($w, $draft), $w['initiator']), FeeReminderNotAllowedException::class);
        $this->assertSame('approved', $this->freshOf($w, $draft)->status);
        $this->assertNoEmailAccepted();
        $this->assertThrows(fn () => $this->draftReminder($w, new FeeReminderSelection(sectionId: $section->id)), FeeReminderNotAllowedException::class);
    }

    private function createdUserIdFor(string $email): string
    {
        return (string) DB::table('users')->where('email', $email)->value('id');
    }
}
