<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Repository-safety checkpoint: prints the RESOLVED (not declared)
 * runtime configuration this process would actually use, so a
 * developer or agent can tell in one command whether ambient
 * container/shell environment has silently shadowed phpunit.xml's
 * intended test values -- the exact class of incident documented in
 * docs/architecture/TEST-ENVIRONMENT-AND-WORKTREE-SAFETY.md. Reads
 * config(), never raw env()/getenv(), because config() is what the
 * application actually uses and is immune to the getenv()/$_SERVER
 * divergence that made the original incident invisible to a naive
 * "just print getenv()" check (see tests/bootstrap.php).
 *
 * Never prints a credential-shaped value -- see the REDACTED_KEYS
 * list below and App\Support\Observability\LogSanitizer for the same
 * pattern applied to structured logs.
 */
class ShowTestEnvironmentDiagnostic extends Command
{
    protected $signature = 'platform:env-diagnostic';

    protected $description = 'Prints non-secret resolved runtime configuration (DB, cache, queue, mail, storage) so ambient environment shadowing of phpunit.xml can be spotted at a glance.';

    public function handle(): int
    {
        $rows = [
            ['APP_ENV', app()->environment()],
            ['APP_DEBUG', config('app.debug') ? 'true' : 'false'],
            ['APP_URL', config('app.url')],
            ['DB_CONNECTION (default)', config('database.default')],
            ['DB_HOST (pgsql)', config('database.connections.pgsql.host')],
            ['DB_PORT (pgsql)', config('database.connections.pgsql.port')],
            ['DB_DATABASE (pgsql)', config('database.connections.pgsql.database')],
            ['DB_DATABASE (pgsql_admin)', config('database.connections.pgsql_admin.database')],
            ['DB_USERNAME (pgsql)', config('database.connections.pgsql.username')],
            ['DB_DATABASE (pgsql_retention)', config('database.connections.pgsql_retention.database')],
            ['DB_RETENTION_USERNAME (pgsql_retention)', config('database.connections.pgsql_retention.username') ?: '(not set)'],
            ['database.testing_database (approved)', config('database.testing_database')],
            ['CACHE_STORE', config('cache.default')],
            ['SESSION_DRIVER', config('session.driver')],
            ['QUEUE_CONNECTION', config('queue.default')],
            ['MAIL_MAILER', config('mail.default')],
            ['MAIL_HOST', config('mail.mailers.smtp.host')],
            ['MAIL_PORT', config('mail.mailers.smtp.port')],
            ['REDIS_HOST (default)', config('database.redis.default.host')],
            ['REDIS_DB (default)', config('database.redis.default.database')],
            ['REDIS_DB (cache)', config('database.redis.cache.database')],
            ['FILESYSTEM_DISK', config('filesystems.default')],
            ['AWS_ENDPOINT (s3 disk)', config('filesystems.disks.s3.endpoint')],
            ['AWS_BUCKET (s3 disk)', config('filesystems.disks.s3.bucket')],
            ['BROADCAST_CONNECTION', config('broadcasting.default')],
            ['COMMUNICATION_EMAIL_ENABLED', config('communications.channels.email.enabled') ? 'true' : 'false'],
            ['COMPOSE_PROJECT_NAME (ambient)', getenv('COMPOSE_PROJECT_NAME') ?: '(not set -- see docker-compose.yml "name:")'],
        ];

        $this->table(['Key', 'Resolved value'], $rows);

        if (app()->environment('testing')) {
            $approved = config('database.testing_database');
            $resolved = config('database.connections.pgsql.database');
            $safe = $resolved === $approved
                && config('cache.default') === 'array'
                && config('queue.default') === 'sync'
                && config('mail.default') === 'array';

            $this->newLine();
            $this->line($safe
                ? '<fg=green>Resolved configuration matches the fail-closed testing contract.</>'
                : '<fg=red>Resolved configuration does NOT match the fail-closed testing contract -- see docs/architecture/TEST-ENVIRONMENT-AND-WORKTREE-SAFETY.md.</>');

            return $safe ? self::SUCCESS : self::FAILURE;
        }

        $this->newLine();
        $this->line('<comment>APP_ENV is not "testing" -- fail-closed contract checks above only apply in the testing environment.</comment>');

        return self::SUCCESS;
    }
}
