<?php

namespace Tests\Feature\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.6F (ADR 0052 amendment, owner decision OWNER-0O6E-2026-09-26): the
 * provider-neutral runtime security contract every production container is
 * started with -- never privileged, ALL capabilities dropped and none added,
 * no-new-privileges, the image's existing non-root user -- plus the setuid
 * removal and fstab invariants the conditional exceptions depend on. These
 * guards stop a later edit from weakening the contract, granting privilege
 * in a production definition, or letting verify-images.sh stop proving it.
 * The built images are proven from /proc/<pid>/status by
 * infrastructure/docker/production/verify-images.sh.
 */
class RuntimeSecurityContractTest extends TestCase
{
    private function path(string $path): string
    {
        return str_starts_with($path, 'apps/platform/')
            ? base_path(substr($path, strlen('apps/platform/')))
            : dirname(base_path(), 2).'/'.$path;
    }

    private function repo(string $path): string
    {
        $this->assertFileExists($this->path($path));

        return (string) file_get_contents($this->path($path));
    }

    /** @return array<string, mixed> */
    private function jsonFile(string $path): array
    {
        return json_decode($this->repo($path), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function contract(): array
    {
        return $this->jsonFile('infrastructure/release/runtime-security.json');
    }

    #[Test]
    public function the_contract_never_grants_privilege_and_its_schema_pins_that(): void
    {
        $contract = $this->contract();

        $this->assertSame([
            'privileged' => false, 'cap_drop' => ['ALL'], 'cap_add' => [], 'no_new_privileges' => true,
            'run_as_non_root' => true, 'read_only_root_filesystem' => false,
        ], $contract['contract']);
        $this->assertSame(['--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true'], $contract['reference_invocation']['docker']);

        $schema = $this->jsonFile('infrastructure/release/schema/runtime-security.schema.json');
        $fields = $schema['properties']['contract']['properties'];
        $this->assertSame(['const' => false], $fields['privileged']);
        $this->assertSame(['const' => ['ALL']], $fields['cap_drop']);
        $this->assertSame(['const' => []], $fields['cap_add']);
        $this->assertSame(['const' => true], $fields['no_new_privileges']);
        $this->assertSame(['const' => true], $fields['run_as_non_root']);
        $this->assertSame(['const' => $contract['reference_invocation']['docker']], $schema['properties']['reference_invocation']['properties']['docker']);
        $this->assertArrayHasKey('read_only_root_filesystem', $contract['future_hardening'], 'the read-only root audit is recorded as future work');
    }

    #[Test]
    public function each_image_runs_as_the_contract_user_and_covers_every_process_role(): void
    {
        $contract = $this->contract()['images'];
        $app = $this->repo('infrastructure/docker/production/app.Dockerfile');
        $gateway = $this->repo('infrastructure/docker/production/ai.Dockerfile');

        $this->assertSame(['www-data', 33], [$contract['app']['user'], $contract['app']['uid']]);
        $this->assertMatchesRegularExpression('/^USER www-data$/m', $app);
        $this->assertSame(['gateway', 10001], [$contract['ai-gateway']['user'], $contract['ai-gateway']['uid']]);
        $this->assertMatchesRegularExpression('/^USER gateway$/m', $gateway);
        $this->assertStringContainsString('useradd --system --uid 10001', $gateway);

        $roles = ['app' => [], 'ai-gateway' => []];
        foreach ($this->jsonFile('apps/platform/deploy/processes.json')['processes'] as $process) {
            $roles[$process['image']][] = $process['role'];
        }
        foreach ($roles as $image => $expected) {
            $this->assertEqualsCanonicalizing($expected, $contract[$image]['roles'], "{$image}: the contract covers every process role in processes.json");
        }
    }

    #[Test]
    public function mount_and_umount_lose_their_setuid_bit_in_both_images(): void
    {
        foreach (['app' => 'app.Dockerfile', 'ai-gateway' => 'ai.Dockerfile'] as $image => $file) {
            $dockerfile = $this->repo("infrastructure/docker/production/{$file}");
            $removed = $this->contract()['images'][$image]['setuid_removed'];
            $this->assertSame(['/usr/bin/mount', '/usr/bin/umount'], $removed);
            foreach ($removed as $binary) {
                $this->assertStringContainsString("dpkg-statoverride --update --add root root 0755 {$binary}", $dockerfile, "{$image}: {$binary} setuid removal");
            }
        }
    }

    #[Test]
    public function no_production_definition_grants_privilege(): void
    {
        $files = array_merge(
            glob($this->path('infrastructure/docker/production').'/*') ?: [],
            glob($this->path('infrastructure/docker/production/runtime-checks').'/*') ?: [],
            glob($this->path('apps/platform/deploy').'/*') ?: [],
            glob($this->path('apps/platform/deploy').'/*/*') ?: [],
            glob($this->path('.github/workflows').'/*.yml') ?: [],
            array_filter(glob($this->path('infrastructure/release').'/*.json') ?: [], fn ($f) => basename($f) !== 'runtime-security.json'),
        );
        $files = array_filter($files, 'is_file');
        $this->assertGreaterThan(10, count($files));

        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression(
                '/privileged:\s*true|--privileged\b|\bcap_add\b|--cap-add\b|\bSYS_ADMIN\b|allowPrivilegeEscalation:\s*true|no-new-privileges[:=]false/i',
                $text,
                basename($file).' grants a privilege the runtime security contract forbids',
            );
        }
    }

    #[Test]
    public function verify_images_starts_every_production_container_hardened_and_proves_each_required_check(): void
    {
        $verify = $this->repo('infrastructure/docker/production/verify-images.sh');

        $this->assertStringContainsString('infrastructure/release/runtime-security.json', $verify);
        $this->assertStringContainsString('["reference_invocation"]["docker"]', $verify);
        $this->assertStringContainsString('drun() { docker run "${HARDEN[@]}" "$@"; }', $verify);

        // Every container of either image runs through drun, except the one deliberate
        // unhardened control that proves the setgid test discriminates.
        preg_match_all('/^.*\bdocker run\b.*"\$(APP_IMAGE|AI_IMAGE)".*$/m', $verify, $bare);
        $this->assertSame(['check "control: without no-new-privileges the same setgid program does gain privilege (the test discriminates)" docker run --rm --entrypoint chage "$APP_IMAGE" -l www-data'],
            array_map('trim', $bare[0]));

        // The kernel is the source of truth: uid, every capability set and NoNewPrivs.
        foreach (['"Uid:"', '"CapEff:"', '"CapPrm:"', '"CapBnd:"', '"CapAmb:"', '"NoNewPrivs:"', 'nnp != "1"', 'uid == "0"'] as $needle) {
            $this->assertStringContainsString($needle, $verify);
        }
        $this->assertStringContainsString('/proc/[0-9]*/status', $verify);
        $this->assertStringContainsString("'false|[\"ALL\"]|null|[\"no-new-privileges:true\"]'", $verify);

        foreach ($this->contract()['images'] as $image => $definition) {
            foreach ($definition['required_verification_checks'] as $name) {
                $this->assertStringContainsString('check "'.$name.'"', $verify, "{$image}: verify-images.sh proves \"{$name}\"");
            }
        }
    }
}
