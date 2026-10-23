<?php

namespace App\Http\Controllers\App;

use App\Domain\Transport\Application\Exceptions\TransportRiderImportFileException;
use App\Domain\Transport\Application\Exceptions\TransportRiderImportNotCommittableException;
use App\Domain\Transport\Application\TransportRiderImport;
use App\Http\Controllers\Controller;
use App\Support\Authorization\Campus\AuthorizesCampusScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * ORG.9C-4 (ADR 0076 §72, PV1-09): add-only bulk rider assignment --
 * upload, preview, commit -- on the existing `transport.assignments.manage`
 * (Campus-adopted) and the one assignment write path. The file is read in
 * this request and never stored; the preview carries only normalized rows
 * and their signature; the commit re-validates everything (TransportRiderImport).
 */
class TransportRiderImportController extends Controller
{
    use AuthorizesCampusScope;

    public function create(TenantContext $context): Response
    {
        $this->authorizeCampusScope(TransportRiderImport::CAPABILITY, $context->requireSchool());

        return Inertia::render('App/Transport/Assignments/Import', ['preview' => null, 'limits' => $this->limits()]);
    }

    /** A blank Lycenza template: the header and one placeholder line, no School data. */
    public function template(TenantContext $context): HttpResponse
    {
        $this->authorizeCampusScope(TransportRiderImport::CAPABILITY, $context->requireSchool());

        return response(implode(',', TransportRiderImport::COLUMNS)."\nSTUDENT-NUMBER,ROUTE-CODE,1,1\n", 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="transport-riders-template.csv"',
        ]);
    }

    public function preview(Request $request, TenantContext $context, TransportRiderImport $import): Response
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope(TransportRiderImport::CAPABILITY, $school);

        $request->validate(['file' => ['required', 'file', 'max:'.(TransportRiderImport::MAX_BYTES / 1024), 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel']]);
        // Read in this request only; PHP removes the upload when it ends.
        $contents = (string) file_get_contents((string) $request->file('file')?->getRealPath());

        try {
            $rows = $import->parse($contents);
        } catch (TransportRiderImportFileException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        }

        return Inertia::render('App/Transport/Assignments/Import', [
            'preview' => ['payload' => $rows, ...$import->preview($school, $scope, $context->actor(), $rows)],
            'limits' => $this->limits(),
        ]);
    }

    public function commit(Request $request, TenantContext $context, TransportRiderImport $import): Response|RedirectResponse
    {
        $school = $context->requireSchool();
        $scope = $this->authorizeCampusScope(TransportRiderImport::CAPABILITY, $school);

        $validated = $request->validate([
            'payload' => ['required', 'array', 'min:1', 'max:'.TransportRiderImport::MAX_ROWS],
            'payload.*.line' => ['required', 'integer', 'min:2'],
            'payload.*.student_number' => ['present', 'nullable', 'string', 'max:64'],
            'payload.*.route_code' => ['present', 'nullable', 'string', 'max:64'],
            'payload.*.pickup_stop' => ['present', 'nullable', 'string', 'max:10'],
            'payload.*.dropoff_stop' => ['present', 'nullable', 'string', 'max:10'],
            'issued_at' => ['required', 'string', 'max:40'],
            'signature' => ['required', 'string', 'size:64'],
        ]);
        // The exact shape parse() produced; anything else fails the signature.
        $rows = array_map(fn (array $r) => [
            'line' => (int) $r['line'],
            'student_number' => (string) ($r['student_number'] ?? ''),
            'route_code' => (string) ($r['route_code'] ?? ''),
            'pickup_stop' => $r['pickup_stop'] ?? null,
            'dropoff_stop' => $r['dropoff_stop'] ?? null,
        ], array_values($validated['payload']));

        try {
            $result = $import->commit($school, $scope, $context->actor(), $rows, $validated['issued_at'], $validated['signature']);
        } catch (TransportRiderImportFileException $e) {
            throw ValidationException::withMessages(['file' => [$e->getMessage()]]);
        } catch (TransportRiderImportNotCommittableException $e) {
            return Inertia::render('App/Transport/Assignments/Import', [
                'preview' => ['payload' => $rows, 'rows' => TransportRiderImport::present($e->outcomes), 'summary' => TransportRiderImport::summary($e->outcomes), 'committable' => false, 'issuedAt' => null, 'signature' => null],
                'refusal' => $e->getMessage(),
                'limits' => $this->limits(),
            ]);
        }

        return redirect('/app/transport/assignments')->with('flash', "Assigned {$result['created']} rider(s); {$result['alreadyAssigned']} already assigned.");
    }

    /** @return array{rows: int, kilobytes: int, columns: list<string>} */
    private function limits(): array
    {
        return ['rows' => TransportRiderImport::MAX_ROWS, 'kilobytes' => TransportRiderImport::MAX_BYTES / 1024, 'columns' => TransportRiderImport::COLUMNS];
    }
}
