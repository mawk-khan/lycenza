<?php

namespace App\Http\Controllers\App;

use App\Domain\Documents\Application\DocumentService;
use App\Domain\Payments\Application\Collections\OutstandingFeesReadService;
use App\Domain\Students\Application\Certificates\StudentCertificateAvailability;
use App\Domain\Students\Application\Certificates\StudentCertificateEligibility;
use App\Domain\Students\Application\Certificates\StudentCertificateReader;
use App\Domain\Students\Application\Certificates\StudentCertificateService;
use App\Domain\Students\Application\Certificates\StudentCertificateType;
use App\Domain\Students\Application\Certificates\StudentCertificateVoidReason;
use App\Domain\Students\Application\Documents\StudentDocumentService;
use App\Domain\Students\Application\Exceptions\StudentCertificateNotEligibleException;
use App\Domain\Students\Infrastructure\StudentCertificate;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\FreshMfaRequirement;
use App\Support\Authorization\Campus\CampusScopeResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Uid\UuidV7;

/**
 * SIS.EXP-4 (OD-SIS-2): a Student's Leaving/Transfer certificates -- staff
 * web only (no API route). Thin: the route carries the floor
 * (`students.certificates.issue` for writes; it or `students.documents.view`
 * for the page), each write re-verifies a fresh MFA code and an explicit
 * confirmation, and StudentCertificateService decides everything else.
 *
 * Finance is issuer CONTEXT only: an issuer who independently holds
 * whole-School `finance.charges.view` and `finance.payments.view` sees the
 * Student's outstanding balance with a note that it never affects issuance;
 * nobody else receives any Finance value, and nothing Financial is printed.
 */
class StudentCertificateController extends Controller
{
    public function index(TenantContext $context, StudentDocumentService $records, StudentCertificateReader $reader, StudentCertificateService $certificates, CampusScopeResolver $scopes, OutstandingFeesReadService $fees, FreshMfaRequirement $mfa, string $student): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $model = $records->visibleStudent($school, $actor, $student);
        $history = $reader->historyFor($school, $actor, $model) ?? [];
        $canIssue = Gate::forUser($actor)->allows('capability', [DocumentService::STUDENT_CERTIFICATES_ISSUE, $school]);

        $preview = null;
        if ($canIssue) {
            $result = $certificates->preview($school, $actor, $model);
            // Reissue fingerprints come from the SAME evaluation (no query per certificate).
            $reissue = [];
            foreach ($history as $row) {
                $type = StudentCertificateType::from($row['type']);
                if ($row['status'] === 'issued') {
                    // Only a certificate of the CURRENT exit can be replaced.
                    $reissue[$row['id']] = $result['eligible'] && in_array($type, $result['types'], true) && $row['sourceEnrollmentId'] === $result['sourceEnrollmentId']
                        ? StudentCertificateEligibility::fingerprint($result['snapshot'], $type, (string) $result['sourceEnrollmentId'], $row['id'])
                        : null;
                }
            }
            $preview = [
                'eligible' => $result['eligible'],
                'refusal' => $result['refusal'] === null ? null : (StudentCertificateNotEligibleException::MESSAGES[$result['refusal']] ?? $result['refusal']),
                'printable' => $result['printable'],
                // A hint only (the service decides): this type already has an issued certificate.
                'types' => array_map(fn (StudentCertificateType $t) => ['value' => $t->value, 'label' => $t->label(), 'fingerprint' => $result['fingerprints'][$t->value],
                    'alreadyIssued' => in_array($t->value, array_column(array_filter($history, fn (array $h) => $h['status'] === 'issued' && $h['sourceEnrollmentId'] === $result['sourceEnrollmentId']), 'type'), true)], $result['types']),
                'snapshot' => $result['snapshot'] === null ? null : [
                    ...$result['snapshot'],
                    'exit_reason_label' => StudentCertificateEligibility::reasonLabel((string) $result['snapshot']['exit_reason']),
                ],
                'reissueFingerprints' => $reissue,
            ];
        }

        $finance = null;
        $financeScope = $scopes->campusScope($actor, 'finance.charges.view', $school)->intersect($scopes->campusScope($actor, 'finance.payments.view', $school));
        if ($canIssue && $financeScope->isWholeSchool()) {
            $row = $fees->list($school, $actor, OutstandingFeesReadService::ALL, null, [$model->id])->rows[0] ?? null;
            $finance = ['outstanding' => $row?->outstanding->amount(), 'currency' => $row?->outstanding->currency()];
        }

        return Inertia::render('App/Students/Certificates', [
            'student' => [
                'id' => $model->id,
                'name' => trim(implode(' ', array_filter([$model->first_name, $model->middle_name, $model->last_name], fn (?string $p) => $p !== null && $p !== ''))),
                'studentNumber' => $model->student_number,
                'status' => $model->status,
            ],
            'history' => $history,
            'canIssue' => $canIssue,
            'available' => StudentCertificateAvailability::isAvailable(),
            'preview' => $preview,
            'finance' => $finance,
            'voidReasons' => array_map(fn (StudentCertificateVoidReason $r) => ['value' => $r->value, 'label' => $r->label()], StudentCertificateVoidReason::plainVoid()),
            'formKey' => (string) new UuidV7,
            'hasMfaFactor' => $mfa->hasActiveFactor($actor),
        ]);
    }

    public function store(Request $request, TenantContext $context, StudentCertificateService $certificates, FreshMfaRequirement $mfa, string $student): RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $validated = $request->validate([
            'type' => ['required', Rule::in(StudentCertificateType::values())],
            'preview_fingerprint' => ['required', 'string', 'size:64'],
            'form_key' => ['required', 'uuid'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirm that the details are correct before issuing.']);
        $proof = $mfa->requireForAction($request, $actor);

        $certificate = $certificates->issue($school, $actor, $student, StudentCertificateType::from($validated['type']), $validated['preview_fingerprint'], $validated['form_key'], $proof);

        return redirect("/app/students/{$student}/certificates")->with('status', $certificate->isIssued()
            ? "{$certificate->certificate_type->label()} {$certificate->display_number} issued."
            : "This request was already completed ({$certificate->display_number}); nothing new was issued.");
    }

    public function void(Request $request, TenantContext $context, StudentCertificateService $certificates, FreshMfaRequirement $mfa, string $student, string $certificate): RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $validated = $request->validate([
            'reason' => ['required', Rule::in(array_map(fn (StudentCertificateVoidReason $r) => $r->value, StudentCertificateVoidReason::plainVoid()))],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirm that this certificate should be voided.']);
        $proof = $mfa->requireForAction($request, $actor);

        $voided = $certificates->void($school, $actor, $student, $certificate, StudentCertificateVoidReason::from($validated['reason']), $proof);

        return redirect("/app/students/{$student}/certificates")->with('status', "{$voided->display_number} voided. Its number is never reused.");
    }

    public function reissue(Request $request, TenantContext $context, StudentCertificateService $certificates, FreshMfaRequirement $mfa, string $student, string $certificate): RedirectResponse
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $validated = $request->validate([
            'preview_fingerprint' => ['required', 'string', 'size:64'],
            'form_key' => ['required', 'uuid'],
            'confirm' => ['accepted'],
        ], ['confirm.accepted' => 'Confirm that the corrected details are right before reissuing.']);
        $proof = $mfa->requireForAction($request, $actor);

        /** @var StudentCertificate $replacement */
        $replacement = $certificates->reissue($school, $actor, $student, $certificate, $validated['preview_fingerprint'], $validated['form_key'], $proof);

        return redirect("/app/students/{$student}/certificates")->with('status', "Replacement {$replacement->display_number} issued; the original is voided.");
    }
}
