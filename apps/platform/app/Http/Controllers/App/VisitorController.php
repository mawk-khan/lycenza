<?php

namespace App\Http\Controllers\App;

use App\Domain\Visitor\Infrastructure\Visitor;
use App\Http\Controllers\Controller;
use App\Support\Audit\AuditRecorder;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Authorization\CapabilityResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 10C -- session-authenticated Inertia pages for the Visitor
 * directory. Mirrors App\Http\Controllers\App\TransportVehicleController's
 * shape exactly.
 */
class VisitorController extends Controller
{
    use AuthorizesCapability;

    public function index(Request $request, TenantContext $context, CapabilityResolver $capabilities): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('visitor.directory.view', $school);

        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:255'],
        ]);

        $query = Visitor::query()->where('status', 'active')->orderBy('full_name');

        if (isset($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(fn ($q) => $q->where('full_name', 'ilike', $term)->orWhere('phone', 'ilike', $term));
        }

        $paginator = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Visitor/Directory/Index', [
            'visitors' => $paginator->through(fn (Visitor $v) => [
                'id' => $v->id,
                'fullName' => $v->full_name,
                'phone' => $v->phone,
                'status' => $v->status,
            ]),
            'filters' => ['search' => $validated['search'] ?? ''],
            'canManage' => $capabilities->canInSchool($context->actor(), 'visitor.directory.manage', $school),
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('visitor.directory.manage', $school);

        return Inertia::render('App/Visitor/Directory/Create');
    }

    public function store(Request $request, TenantContext $context): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('visitor.directory.manage', $school);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        $visitor = DB::transaction(function () use ($school, $validated, $context) {
            $visitor = Visitor::query()->create([...$validated, 'school_id' => $school->id, 'status' => 'active']);

            app(AuditRecorder::class)->school($school, 'visitor.created', actor: $context->actor(), subject: $visitor, metadata: [
                'visitorId' => $visitor->id,
            ]);

            return $visitor;
        });

        return redirect('/app/visitor/directory')->with('flash', 'Visitor added.');
    }

    public function update(Request $request, TenantContext $context, string $visitor): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('visitor.directory.manage', $school);

        $model = Visitor::query()->findOrFail($visitor);

        $validated = $request->validate([
            'full_name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $before = $model->only(array_keys($validated));
        $model->update($validated);

        app(AuditRecorder::class)->school($school, 'visitor.updated', actor: $context->actor(), subject: $model, metadata: [
            'visitorId' => $model->id,
            'statusBefore' => $before['status'] ?? null,
            'statusAfter' => $validated['status'] ?? null,
        ]);

        return redirect('/app/visitor/directory')->with('flash', 'Visitor updated.');
    }
}
