<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Application\Profile\EmployeeDocumentCategory;
use App\Domain\HR\Http\Support\HighlySensitiveDocumentStepUp;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeDocument;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * HTTP transport for HR document records. HR.2A (S-12, D1-26): `store()` is a
 * multipart UPLOAD (`file`) through the platform Documents repository with a
 * closed `category` -- a caller can no longer name a storage path. The
 * response carries `documentId`: the bytes are read through the Documents
 * content endpoint, which re-authorizes by the Document's own tier. No
 * `destroy()` -- `archive()` is the only removal-adjacent action.
 */
class EmployeeDocumentController extends Controller
{
    public function store(Request $request, School $school, string $employee, EmployeeDocumentService $service, HighlySensitiveDocumentStepUp $stepUp): JsonResponse
    {
        abort_if(! Str::isUuid($employee), 404);

        $model = Employee::query()->findOrFail($employee);

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:'.((int) config('documents.max_file_size_mb', 10) * 1024)],
            'category' => ['required', 'string', Rule::in(EmployeeDocumentCategory::values())],
            'classification_tier' => ['required', Rule::in(['restricted', 'highly_sensitive'])],
            'issued_on' => ['sometimes', 'nullable', 'date'],
            'expires_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:issued_on'],
        ]);

        $stepUp->requireFor($request, $request->user(), $school, $validated['classification_tier'], null);
        $document = $service->register(
            $model,
            $request->file('file'),
            EmployeeDocumentCategory::from($validated['category']),
            $validated['classification_tier'],
            ['issued_on' => $validated['issued_on'] ?? null, 'expires_on' => $validated['expires_on'] ?? null],
            $request->user(),
        );

        return response()->json(['data' => $this->present($document)], 201);
    }

    public function update(Request $request, School $school, string $employee, string $document, EmployeeDocumentService $service, HighlySensitiveDocumentStepUp $stepUp): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($document), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $documentModel = EmployeeDocument::query()->where('employee_id', $employeeModel->id)->findOrFail($document);

        $validated = $request->validate([
            'category' => ['sometimes', 'string', Rule::in(EmployeeDocumentCategory::values())],
            'classification_tier' => ['sometimes', Rule::in(['restricted', 'highly_sensitive'])],
            'issued_on' => ['sometimes', 'nullable', 'date'],
            'expires_on' => ['sometimes', 'nullable', 'date'],
        ]);

        $stepUp->requireFor($request, $request->user(), $school, $validated['classification_tier'] ?? $documentModel->classification_tier, $documentModel->classification_tier);
        $updated = $service->update($employeeModel, $documentModel, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function archive(Request $request, School $school, string $employee, string $document, EmployeeDocumentService $service, HighlySensitiveDocumentStepUp $stepUp): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($document), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $documentModel = EmployeeDocument::query()->where('employee_id', $employeeModel->id)->findOrFail($document);

        $stepUp->requireFor($request, $request->user(), $school, $documentModel->classification_tier, $documentModel->classification_tier);
        $archived = $service->archive($employeeModel, $documentModel, $request->user());

        return response()->json(['data' => $this->present($archived)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EmployeeDocument $document): array
    {
        return [
            'id' => $document->id,
            'employeeId' => $document->employee_id,
            // HR.2A: the governed Document holding the bytes (null for a pre-HR.2A record).
            'documentId' => $document->document_id,
            'category' => $document->category,
            'classificationTier' => $document->classification_tier,
            'originalFilename' => $document->original_filename,
            'mimeType' => $document->mime_type,
            'sizeBytes' => $document->size_bytes,
            'issuedOn' => $document->issued_on?->toDateString(),
            'expiresOn' => $document->expires_on?->toDateString(),
            'status' => $document->status,
            'uploadedAt' => $document->uploaded_at->toIso8601String(),
        ];
    }
}
