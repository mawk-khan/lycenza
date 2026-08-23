<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 0A primitive: proves the Laravel -> Inertia -> Vue -> TypeScript
 * request chain end to end. Intentionally has no business logic — future
 * modules must not grow this controller.
 */
class SystemStatusController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('SystemStatus', [
            'appName' => config('app.name'),
            'environment' => config('app.env'),
            'phase' => '0A - Architectural Foundation',
            'requestId' => $request->attributes->get('request_id'),
            'plannedModules' => [
                'Identity & Access', 'Tenancy', 'Schools', 'Students/SIS',
                'Admissions', 'Finance & Fees', 'Academics', 'Attendance',
                'Examinations', 'LMS', 'Communications', 'HR & Payroll',
                'Transport', 'Compliance', 'AI Platform',
            ],
        ]);
    }
}
