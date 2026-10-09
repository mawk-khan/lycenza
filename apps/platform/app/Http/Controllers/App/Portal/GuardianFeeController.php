<?php

namespace App\Http\Controllers\App\Portal;

use App\Domain\Identity\Application\Portal\ActingGuardianResolver;
use App\Domain\Payments\Application\Portal\GuardianFeeReadService;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POR.3 (ADR 0070 §26): a linked Student's fees -- web/Inertia, session only,
 * read-only. Each route carries `portal-development-only`,
 * `capability:portal.fees.view` and `mfa-page` (routes/web.php); here the
 * ActingGuardian is resolved fresh, and GuardianFeeReadService decides the
 * Student and Payment (live scope; anything else the same 404). No payment,
 * refund, export, PDF or family total.
 */
class GuardianFeeController extends Controller
{
    public function index(TenantContext $context, ActingGuardianResolver $guardians, GuardianFeeReadService $fees): Response|RedirectResponse
    {
        $school = $context->requireSchool();
        $students = $fees->students($school, $guardians->require($context->actor(), $school));

        if (count($students) === 1) {
            return redirect("/app/portal/fees/students/{$students[0]['id']}");
        }

        return Inertia::render('App/Portal/Fees/Index', ['schoolName' => $school->name, 'students' => $students]);
    }

    public function show(TenantContext $context, ActingGuardianResolver $guardians, GuardianFeeReadService $fees, string $student): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();
        $guardian = $guardians->require($actor, $school);

        return Inertia::render('App/Portal/Fees/Show', [
            'schoolName' => $school->name,
            'students' => $fees->students($school, $guardian),
            'statement' => $fees->statement($school, $guardian, $actor, $student),
        ]);
    }

    public function payment(TenantContext $context, ActingGuardianResolver $guardians, GuardianFeeReadService $fees, string $student, string $payment): Response
    {
        $school = $context->requireSchool();
        $actor = $context->actor();

        return Inertia::render('App/Portal/Fees/Payment', [
            'schoolName' => $school->name,
            'payment' => $fees->payment($school, $guardians->require($actor, $school), $actor, $student, $payment),
        ]);
    }
}
