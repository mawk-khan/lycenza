<?php

namespace App\Domain\HR\Http\Controllers;

use App\Domain\HR\Application\EmployeeDocumentService;
use App\Domain\HR\Infrastructure\Employee;
use App\Domain\HR\Infrastructure\EmployeeDocument;
use App\Http\Controllers\Controller;
use App\Models\School;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Phase 8A closure correction (item 3) -- HTTP mutation transport for
 * `EmployeeDocument` METADATA ONLY, mirroring `EmployeeDocumentService`'s
 * own structural inability to handle file bytes (see that class's
 * docblock -- this correction was explicitly told NOT to build upload/
 * download, and does not). `storage_path` accepted here is always a
 * relative FRAGMENT, never a final path -- the service derives the real
 * persisted value via `TenantStoragePath`. No `destroy()` -- `archive()`
 * is the only removal-adjacent action (rule 73's reference-entity
 * pattern), matching the service's own lack of a `remove()` method.
 */
class EmployeeDocumentController extends Controller
{
    public function store(Request $request, School $school, string $employee, EmployeeDocumentService $service): JsonResponse
    {
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
            'uploaded_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $document = $service->register($model, $validated, $request->user());

        return response()->json(['data' => $this->present($document)], 201);
    }

    public function update(Request $request, School $school, string $employee, string $document, EmployeeDocumentService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($document), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $documentModel = EmployeeDocument::query()->findOrFail($document);

        $validated = $request->validate([
            'category' => ['sometimes', 'string', 'max:255'],
            'classification_tier' => ['sometimes', Rule::in(['restricted', 'highly_sensitive'])],
            'original_filename' => ['sometimes', 'string', 'max:255'],
            'mime_type' => ['sometimes', 'string', 'max:255'],
            'size_bytes' => ['sometimes', 'integer', 'min:0'],
            'issued_on' => ['sometimes', 'nullable', 'date'],
            'expires_on' => ['sometimes', 'nullable', 'date'],
        ]);

        $updated = $service->update($employeeModel, $documentModel, $validated, $request->user());

        return response()->json(['data' => $this->present($updated)]);
    }

    public function archive(Request $request, School $school, string $employee, string $document, EmployeeDocumentService $service): JsonResponse
    {
        abort_if(! Str::isUuid($employee) || ! Str::isUuid($document), 404);

        $employeeModel = Employee::query()->findOrFail($employee);
        $documentModel = EmployeeDocument::query()->findOrFail($document);

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
