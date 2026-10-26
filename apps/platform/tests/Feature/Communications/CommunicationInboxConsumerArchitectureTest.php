<?php

namespace Tests\Feature\Communications;

use App\Domain\Communications\Application\CommunicationAttachmentService;
use App\Domain\Communications\Application\CommunicationInboxReadModel;
use App\Domain\Communications\Http\Controllers\AnnouncementController;
use App\Domain\Communications\Http\Controllers\CommunicationAttachmentController;
use App\Domain\Communications\Http\Controllers\CommunicationHubController;
use App\Domain\Communications\Http\Controllers\CommunicationInboxController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.9D-1R1 (ADR 0076 §77, OD-9.6): recipient consumption
 * (`communications.inbox.view`) is separate from Communications
 * administration (`communications.view` and the narrower keys). The
 * recipient capability opens exactly the recipient surfaces; each of them
 * still decides per item on actual recipiency or participation, and keeps
 * the creator/manager paths behind the Hub capability. It is never a route
 * middleware, never a management/analytics/approval/template/settings/audit
 * check, and never the Events calendar capability.
 */
class CommunicationInboxConsumerArchitectureTest extends TestCase
{
    private const KEY = 'communications.inbox.view';

    /** Route name => controller method: the ONLY actions that accept the recipient capability. */
    private const RECIPIENT_ROUTES = [
        'app.communications.index' => [CommunicationInboxController::class, 'index'],
        'app.communications.unread' => [CommunicationInboxController::class, 'unread'],
        'app.communications.search' => [CommunicationInboxController::class, 'search'],
        'app.communications.conversations' => [CommunicationHubController::class, 'conversations'],
        'app.communications.show' => [CommunicationHubController::class, 'show'],
        'app.communications.archive' => [CommunicationHubController::class, 'archive'],
        'app.communications.unarchive' => [CommunicationHubController::class, 'unarchive'],
        'app.communications.announcements.show' => [AnnouncementController::class, 'show'],
        'app.communications.attachments.download' => [CommunicationAttachmentController::class, 'download'],
    ];

    private static function source(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $lines = file((string) $reflection->getFileName());

        return implode('', array_slice($lines, $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));
    }

    #[Test]
    public function only_the_recipient_surfaces_name_the_recipient_capability(): void
    {
        $holders = [];
        foreach ((new Finder)->files()->in(base_path('app'))->name('*.php') as $file) {
            if (str_contains($file->getContents(), self::KEY)) {
                $holders[] = 'app/'.$file->getRelativePathname();
            }
        }
        sort($holders);
        $this->assertSame([
            'app/Domain/Communications/Http/Controllers/AnnouncementController.php',
            'app/Domain/Communications/Http/Controllers/CommunicationAttachmentController.php',
            'app/Domain/Communications/Http/Controllers/CommunicationHubController.php',
            'app/Domain/Communications/Http/Controllers/CommunicationInboxController.php',
            'app/Support/Authorization/CapabilityClasses.php',
            'app/Support/Navigation/StaffNavigation.php',
        ], $holders, 'Analytics, approvals, templates, settings, audit and Events code never name it.');

        // Within those controllers, exactly the recipient actions (toggleArchive serves archive/unarchive).
        $expected = ['AnnouncementController::show', 'CommunicationAttachmentController::download', 'CommunicationHubController::conversations',
            'CommunicationHubController::show', 'CommunicationHubController::toggleArchive', 'CommunicationInboxController::index',
            'CommunicationInboxController::search', 'CommunicationInboxController::unread'];
        $found = [];
        foreach ([AnnouncementController::class, CommunicationAttachmentController::class, CommunicationHubController::class, CommunicationInboxController::class] as $class) {
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() === $class && str_contains(self::source($class, $method->getName()), "'".self::KEY."'")) {
                    $found[] = class_basename($class).'::'.$method->getName();
                }
            }
        }
        sort($found);
        $this->assertSame($expected, $found, 'Sent, Failed, the School announcement list and every write stay on their own capabilities.');
    }

    #[Test]
    public function every_recipient_route_pairs_the_capability_with_the_hub_one_and_no_middleware_names_it(): void
    {
        foreach (self::RECIPIENT_ROUTES as $name => [$class, $method]) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertSame($class.'@'.$method, $route->getActionName(), $name);
        }
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                $this->assertFalse(is_string($middleware) && str_contains($middleware, self::KEY), "{$route->getName()} never takes the recipient capability as a route floor.");
            }
        }
        foreach (['index', 'sent'] as $method) {
            $this->assertStringContainsString("authorizeCapability('communications.view', \$school)", self::source($method === 'index' ? AnnouncementController::class : CommunicationInboxController::class, $method), "{$method} stays on communications.view.");
        }
    }

    #[Test]
    public function recipient_detail_needs_actual_participation_and_keeps_admin_paths_behind_the_hub(): void
    {
        $show = self::source(AnnouncementController::class, 'show');
        $this->assertStringContainsString('$recipients->isRecipient($model, $actor)', $show, 'One recipient answer (AnnouncementRecipientAccess).');
        $this->assertStringContainsString('$isCreator = $hubReader &&', $show);
        $this->assertStringContainsString('$canManage = $hubReader &&', $show);
        $this->assertStringContainsString('abort_unless($isCreator || $isRecipient || $canManage, 403);', $show);
        $this->assertStringContainsString("'channelDeliverySummary' => ! \$recipientView", $show, 'No delivery analytics on the recipient view.');

        $access = (string) file_get_contents(base_path('app/Domain/Communications/Application/AnnouncementRecipientAccess.php'));
        $this->assertStringContainsString('! $announcement->isPublished() || $announcement->isFeeReminder()', $access, 'Drafts, scheduled, pending, cancelled and fee reminders have no Hub recipient.');

        $thread = self::source(CommunicationHubController::class, 'show');
        $this->assertStringContainsString("allows('capability', ['communications.view', \$school])\n            && Gate::forUser(\$actor)->allows('capability', ['communications.manage', \$school])", $thread);

        $download = self::source(CommunicationAttachmentController::class, 'download');
        $this->assertStringContainsString('$service->authorizeRead($model, $actor, $school, $recipientOnly)', $download, 'Attachments follow the parent, never the capability alone.');
        $read = self::source(CommunicationAttachmentService::class, 'authorizeRead');
        $this->assertStringContainsString('$this->recipients->isRecipient($announcement, $actor)', $read);
        $this->assertSame(3, substr_count($read, '! $recipientOnly &&'), 'Creator and manager paths are closed on the recipient path.');
    }

    #[Test]
    public function recipient_search_applies_participation_before_matching(): void
    {
        $search = self::source(CommunicationInboxReadModel::class, 'searchAnnouncements');
        $recipientBranch = substr($search, (int) strpos($search, 'if (! $includeAuthored)'), (int) strpos($search, '} else {') - (int) strpos($search, 'if (! $includeAuthored)'));
        $this->assertStringContainsString("->where('status', 'published')", $recipientBranch);
        $this->assertStringContainsString('applyRecipientVisibility', $recipientBranch);
        $this->assertStringNotContainsString('created_by_user_id', $recipientBranch, 'Never authored items on the recipient path.');
        $this->assertStringContainsString('includeAuthored: $hubReader', self::source(CommunicationInboxController::class, 'search'));
    }

    #[Test]
    public function the_events_calendar_keeps_its_own_capability(): void
    {
        $middleware = array_values(array_filter(Route::getRoutes()->getByName('app.communications.calendar')->gatherMiddleware(), fn ($m) => is_string($m) && str_starts_with($m, 'capability:')));
        $this->assertSame(['capability:communications.events.view'], $middleware);
        foreach (self::RECIPIENT_ROUTES as [$class, $method]) {
            $this->assertStringNotContainsString('communications.events.view', self::source($class, $method), 'The inbox never accepts the Events capability.');
        }
        $calendar = (string) file_get_contents(base_path('app/Domain/Communications/Application/Events/CommunicationEventCalendar.php'));
        $this->assertStringNotContainsString(self::KEY, $calendar, 'The calendar never accepts the inbox capability.');
    }
}
