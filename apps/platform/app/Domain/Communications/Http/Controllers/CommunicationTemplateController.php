<?php

namespace App\Domain\Communications\Http\Controllers;

use App\Domain\Communications\Application\CommunicationTemplateService;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Infrastructure\CommunicationTemplate;
use App\Http\Controllers\Controller;
use App\Support\Authorization\AuthorizesCapability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 5A.4 §12 -- thin, capability-gated shell for Template
 * administration, following AnnouncementController's exact shape.
 * `communications.templates.manage` gates every write action here;
 * reading a single active template for composer pre-fill is instead
 * gated by `communications.announce` inside AnnouncementController
 * itself (brief §11's "any authorized announcer can use an existing
 * template" distinction).
 */
class CommunicationTemplateController extends Controller
{
    use AuthorizesCapability;

    public function index(TenantContext $context, Request $request): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.templates.manage', $school);

        $query = CommunicationTemplate::query()->with(['createdBy:id,name'])->orderByDesc('updated_at');

        if ($request->filled('status') && in_array($request->string('status')->value(), ['active', 'inactive'], true)) {
            $query->where('status', $request->string('status')->value());
        }

        if ($request->filled('search')) {
            $query->where('name', 'ilike', '%'.$request->string('search')->value().'%');
        }

        $templates = $query->paginate(20)->withQueryString();

        return Inertia::render('App/Communications/Templates/Index', [
            'templates' => $templates->through(fn (CommunicationTemplate $t) => $this->presentSummary($t)),
            'filters' => ['status' => $request->string('status')->value() ?: null, 'search' => $request->string('search')->value() ?: null],
        ]);
    }

    public function create(TenantContext $context): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.templates.manage', $school);

        return Inertia::render('App/Communications/Templates/Create');
    }

    public function store(Request $request, TenantContext $context, CommunicationTemplateService $service): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.templates.manage', $school);

        $validated = $this->validateTemplate($request);

        $template = $service->create(
            $school,
            $context->actor(),
            $validated['name'],
            $validated['body'],
            $validated['description'] ?? null,
            $validated['subject'] ?? null,
            isset($validated['priority']) ? CommunicationPriority::from($validated['priority']) : null,
        );

        return redirect("/app/communications/templates/{$template->id}/edit");
    }

    public function edit(TenantContext $context, string $template): Response
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.templates.manage', $school);

        $model = CommunicationTemplate::query()->with(['createdBy:id,name'])->findOrFail($template);

        return Inertia::render('App/Communications/Templates/Edit', [
            'template' => $this->presentDetail($model),
        ]);
    }

    public function update(Request $request, TenantContext $context, CommunicationTemplateService $service, string $template): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.templates.manage', $school);

        $model = CommunicationTemplate::query()->findOrFail($template);
        $validated = $this->validateTemplate($request);

        $service->update(
            $model,
            $context->actor(),
            $validated['name'],
            $validated['body'],
            $validated['description'] ?? null,
            $validated['subject'] ?? null,
            isset($validated['priority']) ? CommunicationPriority::from($validated['priority']) : null,
        );

        return redirect("/app/communications/templates/{$model->id}/edit");
    }

    public function activate(TenantContext $context, CommunicationTemplateService $service, string $template): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.templates.manage', $school);

        $model = CommunicationTemplate::query()->findOrFail($template);
        $service->setActive($model, $context->actor(), true);

        return redirect("/app/communications/templates/{$model->id}/edit");
    }

    public function deactivate(TenantContext $context, CommunicationTemplateService $service, string $template): RedirectResponse
    {
        $school = $context->requireSchool();
        $this->authorizeCapability('communications.templates.manage', $school);

        $model = CommunicationTemplate::query()->findOrFail($template);
        $service->setActive($model, $context->actor(), false);

        return redirect("/app/communications/templates/{$model->id}/edit");
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTemplate(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'priority' => ['nullable', 'in:normal,important,urgent,critical'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSummary(CommunicationTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'templateType' => $t->template_type,
            'status' => $t->status,
            'createdByName' => $t->createdBy?->name,
            'updatedAt' => $t->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDetail(CommunicationTemplate $t): array
    {
        return array_merge($this->presentSummary($t), [
            'description' => $t->description,
            'subject' => $t->subject,
            'body' => $t->body,
            'priority' => $t->priority,
        ]);
    }
}
