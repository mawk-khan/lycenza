<?php

namespace Tests\Feature\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.6A (ADR 0052): repository-level supply-chain guards, run as part
 * of the complete regression (and therefore of every release
 * qualification). They check the release INPUTS statically; the built
 * artifacts themselves are verified by infrastructure/release/verify-artifact.
 */
class SupplyChainGuardTest extends TestCase
{
    private const SECRET_NAME = '/(^|_)(PASSWORD|PASSWD|PASSPHRASE|SECRET|SECRETS|TOKEN|TOKENS|KEY|KEYS|CREDENTIAL|CREDENTIALS|PRIVATE)(_|$)/';

    /** Repository files: apps/platform through base_path(), the rest repo-relative (read-only mounts in the Compose test container). */
    private function repo(string $path): string
    {
        $file = str_starts_with($path, 'apps/platform/')
            ? base_path(substr($path, strlen('apps/platform/')))
            : dirname(base_path(), 2).'/'.$path;

        $this->assertFileExists($file);

        return (string) file_get_contents($file);
    }

    /** @return array<string, string> */
    private function productionDockerfiles(): array
    {
        return [
            'app' => $this->repo('infrastructure/docker/production/app.Dockerfile'),
            'ai' => $this->repo('infrastructure/docker/production/ai.Dockerfile'),
        ];
    }

    /** @return array<string, string> */
    private function workflows(): array
    {
        $files = [];
        foreach (['ci.yml', 'release-qualification.yml', 'sbom-rescan.yml'] as $name) {
            $files[$name] = $this->repo('.github/workflows/'.$name);
        }

        return $files;
    }

    #[Test]
    public function every_production_base_image_is_pinned_by_digest(): void
    {
        foreach ($this->productionDockerfiles() as $name => $dockerfile) {
            preg_match_all('/^ARG ([A-Z_]+_IMAGE)=(.+)$/m', $dockerfile, $args, PREG_SET_ORDER);
            $this->assertNotEmpty($args, "{$name}: base images are declared as ARGs");

            foreach ($args as [, $arg, $value]) {
                $this->assertMatchesRegularExpression('/^[a-z0-9][a-z0-9.\/_-]*:[A-Za-z0-9][A-Za-z0-9._-]*@sha256:[0-9a-f]{64}$/', $value, "{$name}: {$arg} must be image:version@sha256:digest");
                $this->assertStringNotContainsString(':latest', $value);
            }

            $declared = array_column($args, 1);
            preg_match_all('/^FROM\s+(\S+)(?:\s+AS\s+(\S+))?/mi', $dockerfile, $froms, PREG_SET_ORDER);
            $stages = [];
            foreach ($froms as $from) {
                $reference = $from[1];
                $viaArg = preg_match('/^\$\{([A-Z_]+)\}$/', $reference, $m) === 1 && in_array($m[1], $declared, true);
                $this->assertTrue($viaArg || in_array($reference, $stages, true), "{$name}: FROM {$reference} must be a pinned base ARG or an earlier stage");
                $stages[] = $from[2] ?? '';
            }
        }
    }

    #[Test]
    public function build_time_secrets_never_travel_as_arg_or_env(): void
    {
        foreach ($this->productionDockerfiles() as $name => $dockerfile) {
            preg_match_all('/^ARG\s+([A-Za-z_][A-Za-z0-9_]*)/m', $dockerfile, $args);
            foreach ($args[1] as $arg) {
                $this->assertMatchesRegularExpression('/^[A-Z]+_IMAGE$/', $arg, "{$name}: only base-image ARGs exist");
            }

            preg_match_all('/^ENV\s+(.+?)(?=^\S|\z)/ms', $dockerfile, $envs);
            foreach ($envs[1] as $block) {
                preg_match_all('/([A-Za-z_][A-Za-z0-9_]*)=/', $block, $names);
                foreach ($names[1] as $env) {
                    $this->assertDoesNotMatchRegularExpression(self::SECRET_NAME, strtoupper($env), "{$name}: ENV {$env} is secret-shaped");
                }
            }

            // A future build-time secret uses BuildKit's secret mount only.
            if (str_contains($dockerfile, '--mount=type=secret')) {
                $this->assertDoesNotMatchRegularExpression('/--mount=type=secret[^\n]*(env=|target=\/?(app|var\/www|srv))/', $dockerfile);
            }
        }
    }

    #[Test]
    public function php_and_frontend_dependencies_install_from_their_locks_without_install_time_code(): void
    {
        $app = $this->productionDockerfiles()['app'];

        $this->assertMatchesRegularExpression('/composer install [^\n]*--no-dev[^\n]*--no-scripts[^\n]*--no-plugins/', $app);
        $this->assertMatchesRegularExpression('/composer dump-autoload [^\n]*--no-scripts[^\n]*--no-plugins/', $app);
        $this->assertDoesNotMatchRegularExpression('/composer (update|require)\b/', $app);
        $this->assertStringContainsString('COPY package.json package-lock.json .npmrc ./', $app);
        $this->assertLessThan(strpos($app, 'RUN npm ci'), strpos($app, 'COPY package.json package-lock.json .npmrc ./'), '.npmrc precedes npm ci');
        $this->assertDoesNotMatchRegularExpression('/npm (install|update|audit fix)|npm i\b|install -g/', $app);

        $composer = json_decode($this->repo('apps/platform/composer.json'), true);
        $this->assertFalse($composer['config']['allow-plugins'], 'no Composer plugin may run');
        $lock = json_decode($this->repo('apps/platform/composer.lock'), true);
        foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
            $this->assertNotSame('composer-plugin', $package['type'] ?? null, "{$package['name']} is a Composer plugin");
        }

        $this->assertMatchesRegularExpression('/^ignore-scripts=true$/m', $this->repo('apps/platform/.npmrc'));
        $npmLock = json_decode($this->repo('apps/platform/package-lock.json'), true);
        foreach ($npmLock['packages'] as $path => $meta) {
            if ($path === '' || ($meta['link'] ?? false) || ($meta['inBundle'] ?? false)) {
                continue;
            }
            $this->assertMatchesRegularExpression('/^sha512-/', $meta['integrity'] ?? '', "{$path} has sha512 integrity");
            $this->assertStringStartsWith('https://registry.npmjs.org/', $meta['resolved'] ?? '', "{$path} comes from the public registry");
        }
    }

    #[Test]
    public function the_gateway_installs_only_hash_verified_wheels_from_the_full_lock(): void
    {
        $ai = $this->productionDockerfiles()['ai'];
        $this->assertStringContainsString('pip install --no-cache-dir --require-hashes --no-deps --only-binary=:all: -r /tmp/requirements.lock', $ai);
        $this->assertStringNotContainsString('requirements.txt', $ai);
        $this->assertDoesNotMatchRegularExpression('/pip install[^\n]*--upgrade/', $ai);

        $lock = $this->repo('services/ai/requirements.lock');
        $entries = preg_split('/\n(?=[a-z0-9])/i', substr($lock, (int) strpos($lock, "\n", (int) strrpos($lock, "#\n"))));
        $pins = [];
        foreach ($entries as $entry) {
            if (preg_match('/^([A-Za-z0-9._-]+)==([^\s\\\\]+)/', trim($entry), $m) !== 1) {
                continue;
            }
            $pins[strtolower($m[1])] = $m[2];
            $this->assertMatchesRegularExpression('/--hash=sha256:[0-9a-f]{64}/', $entry, "{$m[1]} carries a hash");
        }
        $this->assertGreaterThanOrEqual(20, count($pins), 'every transitive package is locked');

        foreach (preg_split('/\R/', $this->repo('services/ai/requirements.txt')) as $line) {
            if (preg_match('/^([A-Za-z0-9._-]+)(?:\[[^\]]+\])?==(\S+)/', trim($line), $m) === 1) {
                $this->assertSame($m[2], $pins[strtolower($m[1])] ?? null, "{$m[1]} is locked at the requirements.txt version");
            }
        }
    }

    #[Test]
    public function every_workflow_action_is_pinned_to_a_full_commit_sha(): void
    {
        foreach ($this->workflows() as $name => $workflow) {
            preg_match_all('/^\s*(?:-\s*)?uses:\s*(.+)$/m', $workflow, $uses);
            $this->assertNotEmpty($uses[1], "{$name} uses actions");
            foreach ($uses[1] as $reference) {
                if (str_starts_with(trim($reference), './')) {
                    continue; // a local action lives in this repository
                }
                $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.\/-]+@[0-9a-f]{40} # v?\d+\.\d+\.\d+$/', trim($reference), "{$name}: {$reference}");
            }
        }
    }

    #[Test]
    public function workflows_are_least_privilege_and_never_run_untrusted_code_with_trust(): void
    {
        foreach ($this->workflows() as $name => $workflow) {
            $this->assertStringNotContainsString('pull_request_target', $workflow, $name);
            $this->assertStringNotContainsString('workflow_run', $workflow, $name);
            $this->assertMatchesRegularExpression('/^permissions:\n  contents: read\n/m', $workflow, "{$name}: workflow-level permissions start at contents: read");
            $this->assertDoesNotMatchRegularExpression('/:\s*write\b|write-all/', $workflow, "{$name}: no write permission");
            $this->assertDoesNotMatchRegularExpression('/id-token|packages:/', $workflow, "{$name}: no signing identity or registry scope");
            $this->assertStringNotContainsString('ubuntu-latest', $workflow, "{$name}: pinned runner image");
            preg_match_all('/secrets\.([A-Za-z_]+)/', $workflow, $secrets);
            $this->assertSame([], $secrets[1], "{$name}: no repository secret is referenced");
            $this->assertDoesNotMatchRegularExpression('/curl[^\n]*\|\s*(ba)?sh|wget[^\n]*\|\s*(ba)?sh/', $workflow, "{$name}: no curl | sh");
            $this->assertDoesNotMatchRegularExpression('/docker (push|login)|cosign (sign|attest)\b|buildx[^\n]*--push/', $workflow, "{$name}: nothing is pushed or signed for real");
            // Tool images run by a step are digest-pinned (CI service containers
            // are test infrastructure, not release inputs: ADR 0052 section 3.2).
            foreach (preg_split('/\R/', $workflow) as $line) {
                if (preg_match('/^\s*image:/', $line) === 1) {
                    continue;
                }
                preg_match_all('/\b([a-z0-9-]+\/[a-z0-9\/-]+:v?\d[\w.-]*)(@sha256:[0-9a-f]{64})?/', $line, $images, PREG_SET_ORDER);
                foreach ($images as $image) {
                    $this->assertNotEmpty($image[2] ?? '', "{$name}: {$image[1]} is pinned by digest");
                }
            }
        }

        $release = $this->workflows()['release-qualification.yml'];
        $this->assertMatchesRegularExpression('/^on:\n  workflow_dispatch: \{\}\n/m', $release, 'release qualification is only dispatched by hand');
        $this->assertStringContainsString("if: github.ref == 'refs/heads/main'", $release);
        $this->assertStringContainsString('git merge-base --is-ancestor "$GITHUB_SHA" origin/main', $release);
        $this->assertStringContainsString('infrastructure/release/qualify all', $release);
        $this->assertStringContainsString('-- infrastructure/release/regression-gates', $release);
        $this->assertStringNotContainsString('cache:', $release, 'release builds use no dependency cache');
        preg_match('/path: (.+)/', $release, $upload);
        $this->assertSame('${{ runner.temp }}/release-evidence', trim($upload[1] ?? ''), 'only the evidence directory is uploaded');
        $this->assertMatchesRegularExpression('/retention-days: \d+/', $release);
    }

    #[Test]
    public function the_source_secret_scan_allowlist_is_exact_match_only(): void
    {
        foreach (['.gitleaks.toml', 'infrastructure/release/gitleaks-image.toml'] as $file) {
            $config = $this->repo($file);
            $this->assertStringContainsString("[extend]\nuseDefault = true", $config, "{$file} keeps gitleaks' default rules");
            $this->assertDoesNotMatchRegularExpression('/disabledRules|^\s*stopwords|^\[allowlist\]|^\[\[allowlists\]\]/m', $config, "{$file}: no global allowlist (a global path exempts the whole file)");
            $this->assertSame(1, substr_count($config, '[[rules]]'), "{$file}: allowlists are scoped to one default rule");
            $this->assertStringContainsString("[[rules]]\nid = \"generic-api-key\"\n", $config);
            $this->assertDoesNotMatchRegularExpression('/^(regex|keywords|entropy|path) =/m', $config, "{$file}: the default rule itself is not redefined");

            $blocks = array_slice(explode('[[rules.allowlists]]', $config), 1);
            $this->assertNotEmpty($blocks);
            foreach ($blocks as $block) {
                $this->assertStringContainsString('condition = "AND"', $block);
                $this->assertStringContainsString('regexTarget = "secret"', $block);
                preg_match("/paths = \\['''(.+?)'''\\]/", $block, $path);
                $this->assertMatchesRegularExpression('/^\^.+\$$/', $path[1] ?? '', "{$file}: one anchored path per entry");
                $this->assertDoesNotMatchRegularExpression('/(?<!\\\\)[.*+](?![^(]*\))|\.\*/', str_replace(['^(?:/repo/)?', '^/image'], '', $path[1] ?? ''), "{$file}: no path wildcard");
                preg_match('/regexes = \\[(.+)\\]/', $block, $regexes);
                preg_match_all("/'''(.+?)'''/", $regexes[1] ?? '', $values);
                foreach ($values[1] as $value) {
                    $this->assertMatchesRegularExpression('/^\^[^*?]+\$$/', $value, "{$file}: exact secret values only");
                }
            }
        }
    }

    #[Test]
    public function the_release_policy_names_no_registry_and_no_signing_identity(): void
    {
        $policy = json_decode($this->repo('infrastructure/release/artifact-policy.json'), true);

        $this->assertSame('unconfigured', $policy['signing']['custody']);
        $this->assertSame(['', '', ''], [$policy['signing']['public_key_ref'], $policy['signing']['certificate_identity'], $policy['signing']['certificate_oidc_issuer']]);
        $this->assertSame(['BUILT', 'VERIFIED'], $policy['states']['repository_reachable']);
        $this->assertArrayNotHasKey('registry', $policy);
        foreach ($policy['tools'] as $tool => $image) {
            $this->assertMatchesRegularExpression('/@sha256:[0-9a-f]{64}$/', $image, "{$tool} is pinned by digest");
        }
        $this->assertSame([], json_decode($this->repo('infrastructure/release/retained-releases.json'), true)['releases'], 'nothing has been promoted');
    }
}
