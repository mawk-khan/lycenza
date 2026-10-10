<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Http\Support\HighlySensitiveDocumentStepUp;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeDocument;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 8A closure correction -- metadata only, same structural
 * inability to handle file bytes as `EmployeeDocumentService` itself
 * (see that class's docblock) and the JSON API's
 * `EmployeeDocumentController`. No route-level `capability:` middleware
 * equivalent needed here (Inertia routes never carry it, per this
 * module's web.php convention) -- the service's own classification-
 * aware check (`hr.employees.documents.manage` vs
 * `hr.employees.sensitive.manage`) remains the sole authority, so this
 * controller does not call `authorizeCapability()` either -- doing so
 * would require guessing the tier before the service decides,
 * which is exactly the risk the JSON API controller's docblock already
 * flagged.
 */
class HrEmployeeDocumentController extends Controller
{
    public function store(Request $request, TenantContext $context, EmployeeDocumentService $service, HighlySensitiveDocumentStepUp $stepUp, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'category' => ['required', 'string', 'max:255'],
            'classification_tier' => ['sometimes', Rule::in(['restricted', 'highly_sensitive'])],
            'storage_disk' => ['required', 'string', 'max:255'],
            'storage_path' => ['required', 'string', 'max:2048'],
            'original_filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', 'max:255'],
            'size_bytes' => ['required', 'integer', 'min:0'],
            'issued_on' => ['sometimes', 'nullable', 'date'],
            'expires_on' => ['sometimes', 'nullable', 'date'],
        ]);

        $stepUp->requireFor($request, $context->actor(), $school, $validated['classification_tier'] ?? null, null);
        $service->register($model, $validated, $context->actor());

        return redirect("/app/hr/employees/{$model->id}");
    }

    public function archive(Request $request, TenantContext $context, EmployeeDocumentService $service, HighlySensitiveDocumentStepUp $stepUp, string $employee, string $document): RedirectResponse
    {
        $school = $context->requireSchool();
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($document), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $documentModel = EmployeeDocument::query()->findOrFail($document);

        $stepUp->requireFor($request, $context->actor(), $school, $documentModel->classification_tier, $documentModel->classification_tier);
        $service->archive($employeeModel, $documentModel, $context->actor());

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }
}
