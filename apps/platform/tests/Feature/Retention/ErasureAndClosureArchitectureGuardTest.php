<?php

namespace Tests\Feature\Retention;

use App\Support\Retention\Erasure\DataSubjectErasurePlanner;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * E21.2F (E21-D10/D11): erasure and closure stay narrow.
 * - Only the operator console reaches the erasure case service; no
 *   controller, job or schedule does.
 * - The subject adapters form a closed map, with no generic table or SQL
 *   executor.
 * - Nothing in the application deletes a School.
 */
class ErasureAndClosureArchitectureGuardTest extends TestCase
{
    /** @return list<string> files under app/ containing $needle */
    private function filesContaining(string $needle): array
    {
        $files = [];
        exec('grep -rlF --include=*.php '.escapeshellarg($needle).' '.escapeshellarg(app_path()), $files);
        sort($files);

        return $files;
    }

    #[Test]
    public function only_the_operator_console_reaches_erasure(): void
    {
        $users = $this->filesContaining('use App\\Support\\Retention\\Erasure\\ErasureCaseService;');

        $this->assertNotEmpty($users);
        foreach ($users as $file) {
            $this->assertStringContainsString('app/Console/Commands/', $file, "{$file} must not reach erasure (operator console only)");
        }

        foreach (['Erasure\\DataSubjectErasurePlanner;', 'Erasure\\ErasureSubjectAdapter;'] as $import) {
            foreach ($this->filesContaining($import) as $file) {
                $this->assertDoesNotMatchRegularExpression('#app/(Http|Jobs|Listeners)/#', $file, "{$file} must not reach erasure");
            }
        }

        $this->assertStringNotContainsString('erasure', (string) file_get_contents(base_path('routes/console.php')), 'erasure is never scheduled');
    }

    #[Test]
    public function the_erasure_adapters_are_a_closed_map_with_no_generic_executor(): void
    {
        $this->assertSame(['student', 'employee', 'guardian', 'user'], array_keys(DataSubjectErasurePlanner::ADAPTERS));

        foreach (glob(app_path('Support/Retention/Erasure/Subjects/*.php')) ?: [] as $file) {
            $code = (string) file_get_contents($file);
            $this->assertStringNotContainsString('DB::statement', $code, $file);
            $this->assertStringNotContainsString('DB::unprepared', $code, $file);
            $this->assertDoesNotMatchRegularExpression('/->delete\(/', $code, "{$file}: adapters delete only through domain purges");
        }
    }

    #[Test]
    public function nothing_in_the_application_deletes_a_school(): void
    {
        foreach (["table('schools')", 'School::query()', 'School::where('] as $needle) {
            foreach ($this->filesContaining($needle) as $file) {
                $this->assertDoesNotMatchRegularExpression('/'.preg_quote($needle, '/').'[^;]*->(delete|forceDelete)\(/s', (string) file_get_contents($file), $file);
            }
        }

        $commands = [];
        exec('grep -rhoE '.escapeshellarg("signature = '[a-z:-]+").' '.escapeshellarg(app_path('Console/Commands')), $commands);
        foreach ($commands as $signature) {
            $this->assertDoesNotMatchRegularExpression('/school-(purge|delete|destroy)/', $signature);
        }
    }
}
