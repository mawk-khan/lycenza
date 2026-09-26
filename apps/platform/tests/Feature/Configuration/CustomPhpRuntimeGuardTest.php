<?php

namespace Tests\Feature\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 0O.6D: the production application runtime is a REPOSITORY-BUILT PHP
 * 8.3.35 linked against curl 8.22.0 and libxml2 2.15.4 (Debian 13 ships
 * vulnerable libcurl/libxml2 and the official PHP binary cannot load the fixed
 * libxml2 ABI). These guards stop a later Dockerfile edit from silently
 * returning to the prebuilt official runtime, or from drifting away from what
 * the documentation and verify-images.sh claim. The built image itself is
 * proven by infrastructure/docker/production/verify-images.sh.
 */
class CustomPhpRuntimeGuardTest extends TestCase
{
    private const PHP_VERSION = '8.3.35';

    private const CURL_VERSION = '8.22.0';

    private const LIBXML2_VERSION = '2.15.4';

    private function repo(string $path): string
    {
        $file = str_starts_with($path, 'apps/platform/')
            ? base_path(substr($path, strlen('apps/platform/')))
            : dirname(base_path(), 2).'/'.$path;

        $this->assertFileExists($file);

        return (string) file_get_contents($file);
    }

    private function dockerfile(): string
    {
        return $this->repo('infrastructure/docker/production/app.Dockerfile');
    }

    /** @return array<string, string> the text of each build stage, keyed by stage name */
    private function stages(): array
    {
        preg_match_all('/^FROM\s+(\S+)\s+AS\s+(\S+)\s*$(.*?)(?=^FROM\s|\z)/ms', $this->dockerfile(), $matches, PREG_SET_ORDER);
        $stages = [];
        foreach ($matches as [, $from, $name, $body]) {
            $stages[$name] = "FROM {$from}\n{$body}";
        }

        return $stages;
    }

    #[Test]
    public function the_runtime_is_not_the_prebuilt_official_php_image(): void
    {
        $stages = $this->stages();

        $this->assertArrayHasKey('sources', $stages);
        $this->assertArrayHasKey('php-build', $stages);
        $this->assertStringStartsWith('FROM ${RUNTIME_IMAGE}', $stages['runtime'] ?? '', 'the runtime starts from plain Debian, not the official PHP image');
        $this->assertMatchesRegularExpression('/^ARG RUNTIME_IMAGE=debian:13\.\d+-slim@sha256:[0-9a-f]{64}$/m', $this->dockerfile());
        $this->assertStringStartsWith('FROM php-build', $stages['vendor'] ?? '', 'Composer runs on the repository-built PHP');
        $this->assertStringContainsString('COPY --from=php-build /usr/local/bin/php /usr/local/bin/php', $stages['runtime']);
        $this->assertStringContainsString('COPY --from=php-build /usr/local/sbin/php-fpm /usr/local/sbin/php-fpm', $stages['runtime']);
        $this->assertMatchesRegularExpression('/^STOPSIGNAL SIGQUIT$/m', $stages['runtime']);
    }

    #[Test]
    public function every_source_is_pinned_and_verified(): void
    {
        $sources = $this->stages()['sources'];

        foreach (['PHP' => self::PHP_VERSION, 'CURL' => self::CURL_VERSION, 'LIBXML2' => self::LIBXML2_VERSION] as $name => $version) {
            $this->assertStringContainsString("LYCENZA_{$name}_VERSION={$version}", $sources);
            $this->assertMatchesRegularExpression("/LYCENZA_{$name}_SHA256=[0-9a-f]{64}\\b/", $sources);
            $this->assertMatchesRegularExpression('/LYCENZA_'.$name.'_URL=https:\/\/\S*'.preg_quote($version, '/').'\.tar\.xz\b/', $sources, "{$name} downloads the exact release over HTTPS");
        }
        $this->assertStringContainsString('| sha256sum -c -', $sources);
        $this->assertMatchesRegularExpression('/LYCENZA_PHP_SIGNER_FINGERPRINTS="[0-9A-F]{40}( [0-9A-F]{40})*"/', $sources);
        $this->assertMatchesRegularExpression('/LYCENZA_CURL_SIGNER_FINGERPRINT=[0-9A-F]{40}\b/', $sources);
        $this->assertSame(2, substr_count($sources, '"VALIDSIG"'), 'PHP and curl signatures are checked against the pinned fingerprints');
        $this->assertDoesNotMatchRegularExpression('/\|\s*(ba)?sh\b|git clone|master|main\.tar/', $sources, 'no piped shell, no moving branch');
    }

    #[Test]
    public function php_is_built_against_the_patched_libraries_only(): void
    {
        $build = $this->stages()['php-build'];

        $this->assertStringContainsString('apt-get purge -y curl libcurl4t64 libxml2', $build);
        $this->assertStringNotContainsString('libcurl4-openssl-dev', str_replace('! dpkg -s libcurl4t64 libxml2 libcurl4-openssl-dev libxml2-dev', '', $build));
        $this->assertStringNotContainsString('libxml2-dev', str_replace('! dpkg -s libcurl4t64 libxml2 libcurl4-openssl-dev libxml2-dev', '', $build));
        $this->assertStringContainsString('export PKG_CONFIG_PATH=/usr/local/lib/pkgconfig', $build);
        foreach (['--with-curl', '--enable-mbstring', '--with-password-argon2', '--with-sodium=shared', '--with-openssl', '--with-readline', '--with-zlib', '--enable-fpm', '--with-fpm-user=www-data', '--disable-cgi'] as $flag) {
            $this->assertStringContainsString($flag, $build, "official configure flag {$flag}");
        }
        $this->assertStringNotContainsString('--with-pear', $build, 'PEAR stays out (its install downloads an unverified phar)');
        $this->assertStringContainsString('docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql bcmath gd opcache pcntl zip', $build);
        $this->assertStringContainsString("php -r 'exit(curl_version()[\"version\"] === \"".self::CURL_VERSION."\" ? 0 : 1);'", $build);
        $this->assertStringContainsString("php -r 'exit(LIBXML_DOTTED_VERSION === \"".self::LIBXML2_VERSION."\" ? 0 : 1);'", $build);
        $this->assertStringContainsString('.note.package', $build, 'the patched libraries carry ELF package metadata for the SBOM');
    }

    #[Test]
    public function the_runtime_carries_no_debian_libcurl_or_libxml2_and_no_toolchain(): void
    {
        $runtime = $this->stages()['runtime'];

        preg_match('/apt-get install -y --no-install-recommends(.*?)&& rm -rf/s', $runtime, $install);
        $packages = preg_split('/[\s\\\\]+/', trim($install[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $this->assertNotEmpty($packages);
        foreach ($packages as $package) {
            $this->assertDoesNotMatchRegularExpression('/^(curl|libcurl.*|libxml2.*|.*-dev|gcc.*|g\+\+.*|make|autoconf|binutils.*)$/', $package, "runtime installs {$package}");
        }
        $this->assertStringContainsString('COPY --from=php-build /usr/local/lib/libcurl.so.4 /usr/local/lib/libcurl.so.4', $runtime);
        $this->assertStringContainsString('COPY --from=php-build /usr/local/lib/libxml2.so.16 /usr/local/lib/libxml2.so.16', $runtime);
        $this->assertStringContainsString('echo /usr/local/lib > /etc/ld.so.conf.d/00-lycenza-native.conf', $runtime);
        $this->assertStringNotContainsString('COPY --from=php-build /usr/local/include', $runtime);
        $this->assertStringNotContainsString('COPY --from=php-build /usr/src', $runtime);
    }

    #[Test]
    public function the_image_verification_proves_the_linkage(): void
    {
        $verify = $this->repo('infrastructure/docker/production/verify-images.sh');

        foreach ([
            'PHP is the repository-built '.self::PHP_VERSION,
            'libcurl '.self::CURL_VERSION.' and libxml2 '.self::LIBXML2_VERSION.' are the loaded runtime versions',
            'php and php-fpm resolve libcurl.so.4 and libxml2.so.16 from /usr/local/lib',
            'no Debian libcurl/libxml2 package and no other copy on disk',
            'PHP module set equals php-modules.expected exactly',
            'native smoke passes inside the production image',
        ] as $check) {
            $this->assertStringContainsString($check, $verify);
        }
        $this->repo('infrastructure/docker/production/runtime-checks/php-native-smoke.php');

        $modules = preg_split('/\R/', trim($this->repo('infrastructure/docker/production/php-modules.expected')));
        foreach (['curl', 'dom', 'libxml', 'SimpleXML', 'xml', 'xmlreader', 'xmlwriter', 'pdo_pgsql', 'pgsql', 'mbstring', 'sodium', 'openssl', 'zip', 'gd', 'bcmath', 'pcntl', 'Zend OPcache'] as $module) {
            $this->assertContains($module, $modules, "{$module} is an expected runtime module");
        }
    }

    #[Test]
    public function the_documentation_matches_the_pinned_runtime(): void
    {
        $runbook = $this->repo('docs/operations/CUSTOM-PHP-RUNTIME.md');

        foreach ([self::PHP_VERSION, self::CURL_VERSION, self::LIBXML2_VERSION] as $version) {
            $this->assertStringContainsString($version, $runbook);
        }
        preg_match_all('/LYCENZA_\w+_SHA256=([0-9a-f]{64})/', $this->dockerfile(), $hashes);
        foreach ($hashes[1] as $hash) {
            $this->assertStringContainsString($hash, $runbook, 'the runbook records every pinned source checksum');
        }
        $this->assertStringContainsString('Lycenza maintains', $runbook);
    }
}
