<?php

namespace Tests\Feature\Students\ProcessingAuthorization;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0H.4D-P2 §53-56: the processing-authorization registry is
 * generic Students/SIS platform infrastructure -- it must never
 * reference Examinations/StudentMark/GradeScale, never substitute
 * Communications consent or StudentGuardianAccountLink for its own
 * authorization decision, and never introduce a Student/Guardian
 * portal route or an outbound event/webhook. Mirrors
 * Tests\Feature\Auth\Mfa\MfaMiddlewareArchitectureGuardTest's
 * source-scan pattern exactly.
 */
class ProcessingAuthorizationArchitectureGuardTest extends TestCase
{
    /** @return list<string> */
    private function processingAuthorizationSources(): array
    {
        return [
            app_path('Domain/Students/Infrastructure/StudentProcessingAuthorization.php'),
            app_path('Domain/Students/Application/StudentProcessingAuthorizationService.php'),
            app_path('Domain/Students/Application/StudentProcessingAuthorizationReadService.php'),
            app_path('Domain/Students/Domain/ProcessingAuthorizationPurpose.php'),
            app_path('Domain/Students/Domain/ProcessingAuthorizationBasisType.php'),
            app_path('Domain/Students/Domain/ProcessingAuthorizationStatus.php'),
            app_path('Domain/Students/Domain/ProcessingAuthorizationAuditActions.php'),
            app_path('Domain/Students/Domain/StudentAge.php'),
            app_path('Http/Controllers/App/StudentProcessingAuthorizationController.php'),
        ];
    }

    private function code(string $file): string
    {
        return preg_replace('#/\*.*?\*/|//[^\n]*#s', '', file_get_contents($file));
    }

    #[Test]
    public function processing_authorization_sources_never_reference_examinations_or_studentmark(): void
    {
        foreach ($this->processingAuthorizationSources() as $file) {
            $code = $this->code($file);

            foreach (['Examination', 'StudentMark', 'GradeScale', 'examinations.', 'marks.'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $code,
                    "{$file} must never reference '{$forbidden}' -- the processing-authorization registry is generic Students/SIS platform infrastructure (Phase 0H.4D-P2).",
                );
            }
        }
    }

    #[Test]
    public function processing_authorization_sources_never_reference_communications_consent(): void
    {
        foreach ($this->processingAuthorizationSources() as $file) {
            $code = $this->code($file);

            foreach (['CommunicationDomainConsentEvent', 'CommunicationConsentService', 'communication_domain_preferences'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $code,
                    "{$file} must never reference '{$forbidden}' -- Communications consent (channel preference) and processing authorization (legal basis) are permanently separate facts.",
                );
            }
        }
    }

    #[Test]
    public function processing_authorization_sources_never_reference_the_guardian_account_link(): void
    {
        foreach ($this->processingAuthorizationSources() as $file) {
            $code = $this->code($file);

            $this->assertStringNotContainsString(
                'StudentGuardianAccountLink',
                $code,
                "{$file} must never reference StudentGuardianAccountLink -- portal-account linkage and legal processing authorization are independent facts.",
            );
        }
    }

    #[Test]
    public function processing_authorization_sources_never_dispatch_a_domain_event(): void
    {
        foreach ($this->processingAuthorizationSources() as $file) {
            $code = $this->code($file);

            foreach (['event(', 'Event::dispatch', 'DomainEventOutbox'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $code,
                    "{$file} must never reference '{$forbidden}' -- zero outbound events by default (Phase 0H.4D-P2 §27/§43).",
                );
            }
        }
    }

    #[Test]
    public function no_student_or_guardian_facing_route_exists_for_processing_authorizations(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'processing-authorizations'));

        $this->assertGreaterThan(0, $routes->count(), 'Expected at least one processing-authorizations route to exist.');

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware, "{$route->uri()} must require auth.");
            $this->assertTrue(
                collect($middleware)->contains(fn ($m) => str_starts_with($m, 'capability:students.processing_authorizations')),
                "{$route->uri()} must require a students.processing_authorizations.* capability.",
            );
            $this->assertContains('mfa', $middleware, "{$route->uri()} must require mfa.");
        }
    }
}
