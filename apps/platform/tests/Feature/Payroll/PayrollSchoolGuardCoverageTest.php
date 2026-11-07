<?php

namespace Tests\Feature\Payroll;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * HR.R1-C15 (C14-N6, rule 86): a structural pin, so that a new Payroll writer has to answer the
 * School question. Every class under `app/Domain/Payroll` that writes either takes the guard as
 * the first statement of each of its transactions, or is listed below with the reason it need not.
 *
 * A source scan sees files, not paths: it proves a class was considered, not that every path is
 * covered. `PayrollExecutionTimeSchoolCheckTest` calls each writer, and
 * `PayrollSchoolSuspensionRaceTest` races the guard against a real suspension.
 */
class PayrollSchoolGuardCoverageTest extends TestCase
{
    private const ROOT = 'Domain/Payroll';

    /** The write shapes: a builder, a static or instance model call, a relation, raw SQL, a retention SQL function. */
    private const WRITE = '/(?<!Carbon)(?<!CarbonImmutable)::create\(|::query\(\)[^;]*->(?:create|forceCreate|update|insert\w*|delete|updateOrCreate|upsert|firstOrCreate|createMany|increment|decrement)\(|->(?:update|forceFill|forceCreate|createMany|increment|decrement|updateOrCreate|firstOrCreate)\(|->save\(\)|->delete\(\)|->create\(\[|DB::(?:insert|update|delete|statement|affectingStatement|unprepared)\(|DB::table\([^)]*\)[^;]*->(?:insert\w*|update|delete|upsert|increment|decrement)\(|::(?:updateOrCreate|firstOrCreate|forceCreate|insert\w*|upsert|destroy)\(|->push\(\)|->(?:updateOrInsert|insert\w*|upsert|touch|restore|forceDelete|saveQuietly|sync|attach|detach)\(|::where\([^;]*->(?:update|delete)\(|DB::select(?:One)?\(\s*[\'"][^\'"]*\b(?:retention_expire_\w+|insert into|update \w+ set|delete from)/is';

    /** Each of these holds the guard first in every transaction it opens. */
    private const GUARDED_FIRST = [
        'Application/PayrollRunService.php' => 6,
        'Application/PayrollPeriodService.php' => 3,
        'Application/SalaryComponentService.php' => 2,
        'Application/SalaryStructureService.php' => 3,
        'Application/CompensationService.php' => 1,
        'Application/PayrollAccountingConfigurationService.php' => 1,
        'Statutory/Application/StatutoryAccountingConfigurationService.php' => 1,
        'Statutory/Application/Admin/EmployeeEsiCoverageAdminService.php' => 1,
        'Statutory/Application/Admin/EmployeePfStatusAdminService.php' => 1,
        'Statutory/Application/Admin/EmployeeTaxProfileAdminService.php' => 1,
        'Statutory/Application/Admin/StatutoryIdentifierAdminService.php' => 1,
        'Statutory/Application/StatutoryPayrollCalculationService.php' => 1,
        'Statutory/Application/StatutoryPayrollPostingService.php' => 2,
    ];

    /** Reaches the guard through `ApprovalDecisionAuthority::requireCurrent()`, inside its own transaction. */
    private const GUARDED_BY_CURRENT_AUTHORITY = [
        'Application/PayrollPostingService.php' => 'post and reverse, under the run lock (rule 142)',
        'Application/PayrollReversalReconciliationService.php' => 'the reconciliation review, under the run lock',
    ];

    /** Writes only inside a caller's transaction that has already asked. */
    private const CHECKED_BY_THE_CALLER = [
        'Application/PayrollCompensationProvenance.php' => 'records sources inside PayrollRunService::calculate()',
        'Application/PayrollPostingIndependence.php' => 'records the attestation and the obligation inside PayrollPostingService',
    ];

    /** Writes, and rightly takes no guard. */
    private const NOT_A_BUSINESS_EFFECT = [
        'Application/Retention/PayrollEmployeeRetentionService.php' => 'platform retention: it must keep running for a suspended School (rule 86)',
        'Application/Retention/PayrollEvidenceRetentionService.php' => 'platform retention (rule 86)',
    ];

    /** The source without comments, so a docblock naming a method is never read as a call. */
    private static function code(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            $out .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? (str_contains($token[1], "\n") ? "\n" : '') : $token[1]) : $token;
        }

        return $out;
    }

    /**
     * Each public method's own body, found by matching braces over PHP's tokens.
     *
     * @return array<string, string>
     */
    private static function publicMethodBodies(string $source): array
    {
        $tokens = token_get_all($source, TOKEN_PARSE);
        $bodies = [];
        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $public = false;
            for ($j = $i - 1; $j >= 0 && (! is_string($tokens[$j]) || ! in_array($tokens[$j], [';', '{', '}'], true)); $j--) {
                $public = $public || (is_array($tokens[$j]) && $tokens[$j][0] === T_PUBLIC);
            }
            $k = $i + 1;
            while ($k < $n && ! (is_array($tokens[$k]) && $tokens[$k][0] === T_STRING)) {
                $k++;
            }
            $name = $tokens[$k][1] ?? '';
            while ($k < $n && $tokens[$k] !== '{' && $tokens[$k] !== ';') {
                $k++;
            }
            if (! $public || ($tokens[$k] ?? ';') === ';') {
                continue;
            }
            $depth = 0;
            $body = '';
            for (; $k < $n; $k++) {
                $text = is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                $depth += ($tokens[$k] === '{' || (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) ? 1 : 0;
                $depth -= $tokens[$k] === '}' ? 1 : 0;
                $body .= $text;
                if ($depth === 0) {
                    break;
                }
            }
            $bodies[$name] = $body;
            $i = $k;
        }

        return $bodies;
    }

    /** The body with every `DB::transaction(...)` call cut out, parentheses matched over PHP's tokens. */
    private static function withoutTransactions(string $body): string
    {
        $tokens = token_get_all('<?php '.$body);
        $out = '';
        for ($i = 1, $n = count($tokens); $i < $n; $i++) {
            $text = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            $starts = $text === 'DB' && ($tokens[$i + 1][1] ?? null) === '::' && ($tokens[$i + 2][1] ?? null) === 'transaction' && ($tokens[$i + 3] ?? null) === '(';
            if (! $starts) {
                $out .= $text;

                continue;
            }
            $depth = 0;
            for ($i += 3; $i < $n; $i++) {
                $depth += $tokens[$i] === '(' ? 1 : ($tokens[$i] === ')' ? -1 : 0);
                if ($depth === 0) {
                    break;
                }
            }
            $out .= '/* transaction */';
        }

        return $out;
    }

    /** @return iterable<string, string> */
    private function payrollClasses(): iterable
    {
        $root = app_path(self::ROOT);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = str_replace($root.'/', '', $file->getPathname());
            if ($file->getExtension() === 'php' && ! str_contains($path, 'Infrastructure/') && ! str_contains($path, 'Exceptions/')) {
                yield $path => self::code((string) file_get_contents($file->getPathname()));
            }
        }
    }

    #[Test]
    public function every_writing_class_answers_rule_86(): void
    {
        $answered = [...self::GUARDED_FIRST, ...self::GUARDED_BY_CURRENT_AUTHORITY, ...self::CHECKED_BY_THE_CALLER, ...self::NOT_A_BUSINESS_EFFECT];
        $unanswered = [];
        foreach ($this->payrollClasses() as $path => $source) {
            if (preg_match(self::WRITE, $source) === 1 && ! array_key_exists($path, $answered)) {
                $unanswered[] = $path;
            }
        }

        $this->assertSame([], $unanswered, 'A Payroll writer answers rule 86: take SchoolOperationalGuard first in its transaction, or say why not here.');
    }

    #[Test]
    public function the_school_is_the_first_thing_each_transaction_asks(): void
    {
        foreach (self::GUARDED_FIRST as $path => $expected) {
            $source = self::code((string) file_get_contents(app_path(self::ROOT.'/'.$path)));
            $transactions = substr_count($source, 'DB::transaction(');
            $guardedFirst = preg_match_all('/DB::transaction\(function \(\)[^{]*\{\n(?:\s*\/\/[^\n]*\n)*\s*\$this->operational->requireOperational\(\$school->id\);/', $source);

            $this->assertSame($expected, $transactions, "{$path}: the number of transactions changed, so check the new one");
            $this->assertSame($transactions, $guardedFirst, "{$path}: rule 86 comes before anything else in the transaction");

            // A listed class could still gain a public method that writes outside its transactions. A private
            // helper is not judged: it is reached only through a public method of the same class.
            foreach (self::publicMethodBodies($source) as $name => $body) {
                if ($name !== '__construct') {
                    $this->assertDoesNotMatchRegularExpression(self::WRITE, self::withoutTransactions($body), "{$path}::{$name}() writes outside a guarded transaction");
                }
            }
        }
    }

    #[Test]
    public function the_current_authority_path_still_reaches_the_guard(): void
    {
        $this->assertStringContainsString('$this->operational->requireOperational(', (string) file_get_contents(app_path('Support/Approval/ApprovalDecisionAuthority.php')));
        foreach (array_keys(self::GUARDED_BY_CURRENT_AUTHORITY) as $path) {
            $source = self::code((string) file_get_contents(app_path(self::ROOT.'/'.$path)));
            $this->assertStringContainsString('->requireCurrent(', $source, $path);
            // Their guard is inside the transaction, after the run row: no public method writes outside one.
            foreach (self::publicMethodBodies($source) as $name => $body) {
                if ($name !== '__construct') {
                    $this->assertDoesNotMatchRegularExpression(self::WRITE, self::withoutTransactions($body), "{$path}::{$name}() writes outside a transaction");
                }
            }
        }
    }

    #[Test]
    public function the_writers_checked_by_their_caller_are_reached_only_from_guarded_classes(): void
    {
        // Any file that names one of the two nested writers and calls a writing method of it.
        $writes = '/(?:->|::)(?:record|recordAffectedBy|withinGuards)\(/';
        $guarded = [...array_keys(self::GUARDED_FIRST), ...array_keys(self::GUARDED_BY_CURRENT_AUTHORITY)];
        $found = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $path = str_replace(app_path(self::ROOT).'/', '', $file->getPathname());
            $source = $file->getExtension() === 'php' ? self::code((string) file_get_contents($file->getPathname())) : '';
            if (! preg_match('/PayrollCompensationProvenance|PayrollPostingIndependence/', $source) || array_key_exists($path, self::CHECKED_BY_THE_CALLER)
                || preg_match($writes, $source) !== 1) {
                continue;
            }
            $found++;
            $this->assertContains($path, $guarded, $file->getPathname().' writes through a nested Payroll writer without the guard');
        }
        $this->assertSame(2, $found, 'PayrollRunService (sources) and PayrollPostingService (attestation, obligations)');
    }

    #[Test]
    public function the_listed_exemptions_still_exist(): void
    {
        foreach ([...array_keys(self::GUARDED_FIRST), ...array_keys(self::GUARDED_BY_CURRENT_AUTHORITY), ...array_keys(self::CHECKED_BY_THE_CALLER), ...array_keys(self::NOT_A_BUSINESS_EFFECT)] as $path) {
            $this->assertFileExists(app_path(self::ROOT.'/'.$path), 'A listed class is gone.');
        }
    }
}
