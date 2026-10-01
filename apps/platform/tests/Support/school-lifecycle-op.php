<?php

use App\Domain\Automation\Application\AutomationExecutionService;
use App\Domain\Communications\Application\Channels\CommunicationChannelRegistry;
use App\Domain\Communications\Application\Policy\CommunicationDeliveryTimingPolicyService;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Platform\Application\Elevation\ElevationDeniedException;
use App\Domain\Platform\Application\Elevation\SchoolElevationService;
use App\Domain\Platform\Application\Schools\SchoolBootstrapAdministrationService;
use App\Domain\Platform\Application\Schools\SchoolLifecycleDeniedException;
use App\Domain\Platform\Application\Schools\SchoolLifecycleService;
use App\Jobs\DeliverWebhookJob;
use App\Jobs\ProcessCommunicationDeliveryJob;
use App\Models\School;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for SchoolLifecycleConcurrencyTest: one
// School lifecycle operation, or one School business effect, in a
// GENUINELY separate OS process, so two real PHP processes race against
// real PostgreSQL. Mirrors group-authority-op.php.
//
// Usage:
//   php school-lifecycle-op.php activate  <actorId> <schoolId> <recoveryCode>
//   php school-lifecycle-op.php suspend   <actorId> <schoolId> <recoveryCode>
//   php school-lifecycle-op.php close     <actorId> <schoolId> <recoveryCode>   (E21.2F)
//   php school-lifecycle-op.php replace   <actorId> <schoolId> <targetEmail> <recoveryCode>
//   php school-lifecycle-op.php elevate   <actorId> <schoolId> <recoveryCode>
//   php school-lifecycle-op.php hold-operational  <schoolId>
//   php school-lifecycle-op.php deliver-communication <schoolId> <deliveryId>
//   php school-lifecycle-op.php deliver-webhook       <schoolId> <deliveryId>
//   php school-lifecycle-op.php run-automation        <schoolId> <executionId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);

$request = Request::create('/app/platform/schools', 'POST');
$session = $app->make('session')->driver('array');
$session->start();
$request->setLaravelSession($session);
$app->instance('request', $request);

try {
    $result = HeldTransaction::run(function () use ($app, $request, $operation, $args) {
        $lifecycle = $app->make(SchoolLifecycleService::class);
        $context = $app->make(TenantContext::class);

        switch ($operation) {
            case 'activate':
                [$actorId, $schoolId, $code] = $args;
                $lifecycle->activate($request, User::query()->findOrFail($actorId), School::query()->findOrFail($schoolId), true, $code);

                return 'activated';
            case 'suspend':
                [$actorId, $schoolId, $code] = $args;
                $lifecycle->suspend($request, User::query()->findOrFail($actorId), School::query()->findOrFail($schoolId), 'security_incident', true, $code);

                return 'suspended';
            case 'close':
                [$actorId, $schoolId, $code] = $args;
                $lifecycle->close($request, User::query()->findOrFail($actorId), School::query()->findOrFail($schoolId), 'ceased_operations', true, $code);

                return 'closed';
            case 'replace':
                [$actorId, $schoolId, $email, $code] = $args;
                $app->make(SchoolBootstrapAdministrationService::class)->replace($request, User::query()->findOrFail($actorId), School::query()->findOrFail($schoolId), $email, true, $code);

                return 'replaced';
            case 'elevate':
                [$actorId, $schoolId, $code] = $args;
                $app->make(SchoolElevationService::class)->start($request, User::query()->findOrFail($actorId), $schoolId, 'operational_support', true, $code);

                return 'started';
            case 'hold-operational':
                [$schoolId] = $args;

                return $app->make(SchoolOperationalGuard::class)->holdOperational($schoolId) ? 'held:active' : 'held:not-active';
            case 'deliver-communication':
                [$schoolId, $deliveryId] = $args;
                (new ProcessCommunicationDeliveryJob($schoolId, $deliveryId))->handle(
                    $app->make(CommunicationChannelRegistry::class),
                    $context,
                    $app->make(CommunicationDeliveryTimingPolicyService::class),
                );
                $school = School::query()->findOrFail($schoolId);

                return 'delivery:'.$context->withSchool($school, fn () => CommunicationDelivery::query()->findOrFail($deliveryId)->status);
            case 'deliver-webhook':
                [$schoolId, $deliveryId] = $args;
                DeliverWebhookJob::dispatchSync($schoolId, $deliveryId);
                $school = School::query()->findOrFail($schoolId);

                return 'delivery:'.$context->withSchool($school, fn () => WebhookDelivery::query()->findOrFail($deliveryId)->status);
            case 'run-automation':
                [$schoolId, $executionId] = $args;
                $school = School::query()->findOrFail($schoolId);
                $context->withSchool($school, fn () => $app->make(AutomationExecutionService::class)->run($school, $executionId));

                return 'ran';
            default:
                throw new InvalidArgumentException("Unknown operation {$operation}");
        }
    });
    echo $result;
} catch (SchoolLifecycleDeniedException $e) {
    echo 'rejected:'.$e->outcome;
} catch (ElevationDeniedException $e) {
    echo 'rejected:'.$e->outcome;
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
}
