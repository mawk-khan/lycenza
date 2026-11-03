<?php

namespace App\Http\Controllers\App\HR;

use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Application\Profile\EmployeeDocumentCategory;
use App\Domain\HR\Http\Support\HighlySensitiveDocumentStepUp;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeDocument;
use App\Http\Controllers\Controller;
use App\Support\Auth\Mfa\SensitiveReadAssurance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR document records on the Profile (staff web). HR.2A (S-12, D1-26): a new
 * record is an UPLOAD through the platform Documents repository
 * (EmployeeDocumentService::register() -> DocumentService::createForEmployee()),
 * with a closed category -- never a typed storage path. No route-level
 * `capability:` middleware on the writes: the service's classification-aware
 * check (`hr.employees.documents.manage` vs `hr.employees.sensitive.manage`)
 * is the authority, since the tier is part of the request. A Highly Sensitive
 * write needs a fresh MFA code (HighlySensitiveDocumentStepUp); a Highly
 * Sensitive download needs current MFA assurance, and every download is
 * re-authorized by the Document's own tier.
 */
class HrEmployeeDocumentController extends Controller
{
    public function store(Request $request, TenantContext $context, EmployeeDocumentService $service, HighlySensitiveDocumentStepUp $stepUp, string $employee): RedirectResponse
    {
        $school = $context->requireSchool();
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.((int) config('documents.max_file_size_mb', 10) * 1024)],
            'category' => ['required', 'string', Rule::in(EmployeeDocumentCategory::values())],
            'classification_tier' => ['required', Rule::in(['restricted', 'highly_sensitive'])],
            'issued_on' => ['sometimes', 'nullable', 'date'],
            'expires_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:issued_on'],
        ], [
            'file.*' => 'Choose a PDF, JPEG or PNG file.',
            'category.*' => 'Choose a document category from the list.',
            'expires_on.after_or_equal' => 'The expiry date cannot be before the issue date.',
        ]);

        $stepUp->requireFor($request, $context->actor(), $school, $validated['classification_tier'], null);
        $service->register(
            $model,
            $request->file('file'),
            EmployeeDocumentCategory::from($validated['category']),
            $validated['classification_tier'],
            ['issued_on' => $validated['issued_on'] ?? null, 'expires_on' => $validated['expires_on'] ?? null],
            $context->actor(),
        );

        return redirect("/app/hr/employees/{$model->id}");
    }

    public function archive(Request $request, TenantContext $context, EmployeeDocumentService $service, HighlySensitiveDocumentStepUp $stepUp, string $employee, string $document): RedirectResponse
    {
        $school = $context->requireSchool();
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($document), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $documentModel = EmployeeDocument::query()->where('employee_id', $employeeModel->id)->findOrFail($document);

        $stepUp->requireFor($request, $context->actor(), $school, $documentModel->classification_tier, $documentModel->classification_tier);
        $service->archive($employeeModel, $documentModel, $context->actor());

        return redirect("/app/hr/employees/{$employeeModel->id}");
    }

    /**
     * HR.2A: the record's file, re-authorized by its Document's tier on every
     * download; a Highly Sensitive file also needs current MFA assurance.
     * Always an attachment, never rendered inline.
     */
    public function download(Request $request, TenantContext $context, EmployeeDocumentService $service, SensitiveReadAssurance $assurance, string $employee, string $document): StreamedResponse
    {
        $context->requireSchool();
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($document), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $documentModel = EmployeeDocument::query()->where('employee_id', $employeeModel->id)->findOrFail($document);
        if ($documentModel->classification_tier === 'highly_sensitive') {
            $assurance->requireForPage($request);
        }

        $content = $service->content($employeeModel, $documentModel, $context->actor());

        return response()->streamDownload(function () use ($content) {
            fpassthru($content->stream);

            if (is_resource($content->stream)) {
                fclose($content->stream);
            }
        }, $content->originalFilename, [
            'Content-Type' => $content->mimeType,
            'Content-Length' => (string) $content->sizeBytes,
            'X-Content-Type-Options' => 'nosniff',
        ], 'attachment');
    }
}
