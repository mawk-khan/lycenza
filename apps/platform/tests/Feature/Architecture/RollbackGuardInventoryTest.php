<?php

namespace Tests\Feature\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HR.R1-C5 (PAY-15): the static half of rollback safety. It proves nothing about
 * behaviour (`RollbackSafeMigratorTest` does, on real roles); it keeps the
 * inventory complete and keeps a `down()` from undoing the fail-closed runner.
 * Known limits: it reads source, follows same-file helpers one level only and no
 * helper in another file, and a guard built only inside raw SQL with no `throw` or
 * "refuse" is not seen.
 */
#[Group('repository-documents')]
class RollbackGuardInventoryTest extends TestCase
{
    /** @return array<string, string> migration name => its down() body */
    private function downs(): array
    {
        $downs = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (preg_match('/function down\(\)[^{]*\{/', $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }
            $body = substr($source, $m[0][1] + strlen($m[0][0]));
            if (preg_match('/\n    (public|private|protected) (static )?function /', $body, $next, PREG_OFFSET_CAPTURE) === 1) {
                $body = substr($body, 0, $next[0][1]);
            }
            preg_match_all('/(?:\$this->|self::)([a-zA-Z_]+)\(/', $body, $calls);
            foreach (array_unique($calls[1]) as $helper) {
                if (preg_match('/function '.$helper.'\([^)]*\)[^{]*\{(.*?)\n    \}/s', $source, $found) === 1) {
                    $body .= "\n".$found[1];
                }
            }
            $downs[basename($file, '.php')] = $body;
        }

        return $downs;
    }

    #[Test]
    public function every_guard_is_in_the_inventory(): void
    {
        $inventory = (string) file_get_contents(base_path('../../docs/hr/PAY-15-ROLLBACK-GUARD-INVENTORY.md'));
        $missing = [];
        foreach ($this->downs() as $name => $body) {
            if (preg_match('/throw new|[Rr]efus(e|ing)/', $body) === 1 && ! str_contains($inventory, "`{$name}`")) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, 'list every new guard in docs/hr/PAY-15-ROLLBACK-GUARD-INVENTORY.md');
    }

    #[Test]
    public function a_down_never_turns_an_error_into_a_pass(): void
    {
        foreach ($this->downs() as $name => $body) {
            preg_match_all('/catch\s*\([^)]*\)\s*\{(.*?)\n\s*\}/s', $body, $catches);
            foreach ($catches[1] as $handler) {
                $this->assertStringContainsString('throw', $handler, "{$name} swallows an error in down()");
            }
        }
    }

    #[Test]
    public function a_down_stays_on_the_migration_connection_and_never_changes_the_setting(): void
    {
        foreach ($this->downs() as $name => $body) {
            $this->assertDoesNotMatchRegularExpression('/DB::connection\(\s*[^)\s]|Schema::connection\(/', $body, "{$name} reads through a named connection");
            $this->assertDoesNotMatchRegularExpression('/row_security|\brescue\(|EXCEPTION\s+WHEN|RESET\s+ALL|\bCOMMIT\b/i', $body, "{$name} can undo the fail-closed runner");
        }
    }

    #[Test]
    public function no_migration_ever_gives_a_role_rls_bypass(): void
    {
        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            $this->assertDoesNotMatchRegularExpression('/\b(ALTER|CREATE)\s+ROLE\b[^;\n]*\bBYPASSRLS\b/i', (string) file_get_contents($file), basename($file));
        }
    }

    #[Test]
    public function no_migration_runs_outside_a_transaction(): void
    {
        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            $this->assertDoesNotMatchRegularExpression('/withinTransaction\s*=\s*false/', (string) file_get_contents($file), basename($file));
        }
    }
}
