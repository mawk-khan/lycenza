<?php

use App\Domain\Platform\Application\Groups\Reporting\GroupCurriculumCoverageReportService;
use App\Domain\Platform\Application\Groups\SchoolGroupGovernanceService;
use App\Models\GroupRoleAssignment;
use App\Models\School;
use App\Models\SchoolGroup;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\Concurrency\HeldTransaction;

// Standalone bootstrap script for GroupReportConcurrencyTest: a Group
// report, or the Group governance change racing it, in a GENUINELY
// separate OS process against real PostgreSQL. Mirrors group-authority-op.php.
//
// Usage:
//   php group-report-op.php report <actorId> <groupId>
//   php group-report-op.php remove <platformActorId> <groupId> <schoolId>
//   php group-report-op.php revoke <platformActorId> <grantId>

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$args = array_slice($argv, 1);
$operation = array_shift($args);

try {
    $result = HeldTransaction::run(function () use ($app, $operation, $args) {
        $governance = $app->make(SchoolGroupGovernanceService::class);

        switch ($operation) {
            case 'report':
                [$actorId, $groupId] = $args;
                $request = Request::create('/app/groups/'.$groupId.'/reports/curriculum-coverage', 'GET');
                $session = $app->make('session')->driver('array');
                $session->start();
                $session->put('mfa_verified_at', now()->toIso8601String());
                $request->setLaravelSession($session);
                $app->instance('request', $request);
                $report = $app->make(GroupCurriculumCoverageReportService::class)
                    ->generate($request, User::query()->findOrFail($actorId), SchoolGroup::query()->findOrFail($groupId));
                $included = array_column(array_filter($report['schools'], fn (array $s) => $s['state'] === 'included'), 'schoolId');
                sort($included);

                return 'report:'.implode(',', $included);
            case 'remove':
                [$actorId, $groupId, $schoolId] = $args;
                $governance->removeSchool(User::query()->findOrFail($actorId), SchoolGroup::query()->findOrFail($groupId), School::query()->findOrFail($schoolId));

                return 'removed';
            case 'revoke':
                [$actorId, $grantId] = $args;
                $governance->revoke(User::query()->findOrFail($actorId), GroupRoleAssignment::query()->findOrFail($grantId));

                return 'revoked';
            default:
                throw new InvalidArgumentException("Unknown operation {$operation}");
        }
    });
    echo $result;
} catch (NotFoundHttpException) {
    echo 'failed:authority_lost';
} catch (Throwable $e) {
    echo 'error:'.$e::class.':'.$e->getMessage();
}
