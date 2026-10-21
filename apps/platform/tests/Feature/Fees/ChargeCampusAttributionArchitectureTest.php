<?php

namespace Tests\Feature\Fees;

use App\Domain\Fees\Application\AssessChargeData;
use App\Domain\Fees\Application\ChargeCampusAttribution;
use App\Domain\Fees\Application\Sources\FeeSourceChargeData;
use App\Domain\Identity\Application\Staff\StaffRoleCatalog;
use App\Models\Role;
use App\Support\Authorization\Campus\CampusScopeCatalogue;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * ORG.7G-1A (ADR 0076 §49): the Charge Campus contract is machine-checked --
 * every Charge creator states its attribution source, the Campus is stored and
 * frozen in the database, nothing re-derives it for Finance, Payments stay
 * School-wide, and no Finance capability or Finance role became Campus-aware.
 */
class ChargeCampusAttributionArchitectureTest extends TestCase
{
    /** Every production construction of a Charge, and the attribution source it must use. */
    private const CREATORS = [
        'app/Domain/Canteen/Application/CanteenOrderService.php' => 'ChargeCampusAttribution::fromCanteenOutlet(',
        'app/Domain/Fees/Application/FeeAssessmentItemExecutor.php' => 'ChargeCampusAttribution::fromEnrollment(',
        'app/Domain/Fees/Application/Sources/FeeSourceChargeService.php' => 'campus: $data->campus',
        'app/Domain/Fees/Http/Controllers/ChargeController.php' => 'ChargeCampusAttribution::placementOnAssessmentDate()',
        'app/Domain/Payments/Application/LateFeeItemExecutor.php' => 'ChargeCampusAttribution::fromCharge(',
        'app/Http/Controllers/App/Finance/ChargeController.php' => 'ChargeCampusAttribution::placementOnAssessmentDate()',
        'database/seeders/Demo/DemoModuleData.php' => 'ChargeCampusAttribution::placementOnAssessmentDate()',
    ];

    /** Every source-module Charge (the event-charge seam), and its source. */
    private const SOURCE_CREATORS = [
        'app/Domain/Library/Application/LibraryFineService.php' => 'ChargeCampusAttribution::fromLibraryLoan(',
    ];

    /** @return array<string, string> relative path => contents, for files containing $needle */
    private function filesContaining(string $needle): array
    {
        $out = [];
        foreach (Finder::create()->files()->in([app_path(), database_path()])->name('*.php') as $file) {
            if (str_contains($file->getContents(), $needle)) {
                $out[substr($file->getRealPath(), strlen(base_path()) + 1)] = $file->getContents();
            }
        }
        ksort($out);

        return $out;
    }

    #[Test]
    public function every_charge_creator_states_its_campus_attribution_source(): void
    {
        foreach (['new AssessChargeData(' => self::CREATORS, 'new FeeSourceChargeData(' => self::SOURCE_CREATORS] as $needle => $expected) {
            $found = $this->filesContaining($needle);
            $this->assertSame(array_keys($expected), array_keys($found), "a new {$needle} caller needs an explicit, reviewed attribution source");
            foreach ($expected as $file => $source) {
                $this->assertStringContainsString($source, $found[$file], "{$file} attributes from its historical source");
            }
        }

        foreach ([AssessChargeData::class, FeeSourceChargeData::class] as $class) {
            $campus = collect((new ReflectionClass($class))->getConstructor()?->getParameters())->firstWhere('name', 'campus');
            $this->assertNotNull($campus, "{$class} carries the attribution");
            $this->assertFalse($campus->isOptional(), "{$class}: the attribution is required, never defaulted");
            $type = $campus->getType();
            $this->assertTrue($type instanceof ReflectionNamedType && $type->getName() === ChargeCampusAttribution::class && ! $type->allowsNull());
        }
        $this->assertTrue((new ReflectionClass(ChargeCampusAttribution::class))->getConstructor()?->isPrivate(), 'no caller can pass an arbitrary Campus');
        $this->assertSame(['app/Domain/Fees/Application/ChargeService.php'], array_keys(array_diff_key(
            $this->filesContaining('->withResolvedCampus('),
            ['app/Domain/Fees/Application/ChargeCampusAttribution.php' => true],
        )), 'only ChargeService::assess() resolves a deferred placement attribution');
    }

    #[Test]
    public function only_charge_service_inserts_charges(): void
    {
        $writers = [];
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $code = $file->getContents();
            if (preg_match("/\\bCharge::query\(\)->create\(|\\bCharge::create\(|DB::table\('charges'\)->insert|INSERT INTO charges\\b/i", $code)) {
                $writers[] = substr($file->getRealPath(), strlen(base_path()) + 1);
            }
        }
        $this->assertSame(['app/Domain/Fees/Application/ChargeService.php'], $writers);
    }

    #[Test]
    public function finance_never_re_derives_a_charge_campus_or_uses_the_current_campus(): void
    {
        foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
            $this->assertStringNotContainsString('campusAttribution(', $file->getContents(), $file->getRelativePathname().': the runtime re-derivation is retired');
        }
        foreach (['Domain/Fees', 'Domain/Payments', 'Domain/Finance', 'Http/Controllers/App/Finance'] as $dir) {
            foreach (Finder::create()->files()->in(app_path($dir))->name('*.php') as $file) {
                foreach (['StudentOperationalCampusResolver', 'StudentOperationalEligibility', 'StudentPurposeLookup', 'constrainToCurrentPlacement'] as $current) {
                    $this->assertStringNotContainsString($current, $file->getContents(), "{$dir}/{$file->getRelativePathname()}: a Charge's Campus is never the current Campus");
                }
            }
        }
        $controller = (string) file_get_contents(app_path('Http/Controllers/App/Finance/OutstandingFeesController.php'));
        $this->assertStringContainsString('ChargeCampusFilter::campus(', $controller, 'the Campus filter is the stored-Campus SQL filter');
        $this->assertStringNotContainsString('Closure', (string) file_get_contents(app_path('Domain/Payments/Application/Collections/OutstandingFeesReadService.php')), 'no PHP filtering of charge lines');
    }

    #[Test]
    public function the_database_stores_freezes_and_source_binds_the_charge_campus(): void
    {
        $admin = DB::connection('pgsql_admin');
        $this->assertSame('FOREIGN KEY (campus_id, school_id) REFERENCES campuses(id, school_id) ON DELETE RESTRICT', $admin->selectOne("select pg_get_constraintdef(oid) as d from pg_constraint where conname = 'charges_campus_fk'")->d);
        $this->assertStringContainsString('NEW.campus_id IS DISTINCT FROM OLD.campus_id', (string) $admin->selectOne("select prosrc from pg_proc where proname = 'fees_reject_recognized_charge_mutation'")->prosrc);

        $triggers = collect($admin->select("select tgname from pg_trigger where tgname like '%charge_campus_guard'"))->pluck('tgname')->sort()->values()->all();
        $this->assertSame(['canteen_orders_charge_campus_guard', 'fee_assessments_charge_campus_guard', 'late_fee_assessments_charge_campus_guard', 'library_fines_charge_campus_guard'], $triggers);

        foreach (['payments', 'payment_allocations', 'payment_receipts', 'payment_corrections', 'payment_correction_lines'] as $table) {
            $this->assertSame(0, (int) $admin->selectOne("select count(*) as n from information_schema.columns where table_schema = 'public' and table_name = ? and column_name = 'campus_id'", [$table])->n, "{$table} stays School-wide (G1A-D4)");
        }
    }

    #[Test]
    public function only_the_finance_read_keys_are_campus_aware_and_accountant_stays_whole_school(): void
    {
        // ORG.7G-1 (ADR 0076 §50) superseded ORG.7G-1A's "no Finance key adopted"
        // pin with the owner-approved read adoption (G1-D9..G1-D11).
        $finance = array_values(array_filter(array_keys(CampusScopeCatalogue::ADOPTED), fn (string $k) => str_starts_with($k, 'finance.')));
        sort($finance);
        $this->assertSame(['finance.charges.view', 'finance.payments.record', 'finance.payments.view'], $finance, 'reads (ORG.7G-1) and payment entry (ORG.7G-2) only -- no configuration, run, correction or ledger key');
        $roles = Role::query()->with('capabilities')->where('scope', Role::SCOPE_SCHOOL)->whereIn('key', ['cashier', 'accountant', 'principal'])->get()->keyBy('key');
        $this->assertTrue(StaffRoleCatalog::campusScopable($roles['cashier']), 'cashier is the Campus Finance persona (G1-D9)');
        $this->assertFalse(StaffRoleCatalog::campusScopable($roles['accountant']), 'accountant stays whole-School (G1-D10)');
        $this->assertSame([], $roles['principal']->capabilities->pluck('key')->filter(fn ($k) => str_starts_with($k, 'finance.'))->values()->all(), 'principal holds no Finance key (G1-D11)');
    }
}
