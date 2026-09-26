<?php

/**
 * Phase 0O.6D: native-runtime smoke test for the REPOSITORY-BUILT PHP
 * (8.3.35 against curl 8.22.0 / libxml2 2.15.4), run INSIDE the production
 * application image by verify-images.sh. It exercises the application's own
 * HTTP client stack (Laravel HTTP client -> Guzzle -> libcurl, with the
 * webhook delivery options: CURLOPT_RESOLVE pinning, no redirects, connect and
 * total timeouts), TLS verification against a throwaway CA, the AWS SDK
 * against a real MinIO (HTTP + XML parsing), and every PHP XML API -- repeated,
 * so an ABI mismatch shows up as a failure or a crash rather than a pass.
 *
 * Never shipped in the image (mounted read-only at run time). Prints one
 * "PASS|FAIL <name>" line per check; exits non-zero on any failure.
 *
 * Env: TLS_HOST (test HTTPS server), TLS_IP (its address), TLS_CA (test CA
 * file), S3_ENDPOINT, S3_KEY, S3_SECRET, ROUNDS.
 */

require '/var/www/app/vendor/autoload.php';

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

$failures = 0;
$check = function (string $name, callable $test) use (&$failures): void {
    try {
        $ok = $test();
    } catch (Throwable $e) {
        $ok = false;
        $name .= ' ['.get_class($e).']';
    }
    if (! $ok) {
        $failures++;
    }
    echo ($ok ? 'PASS  ' : 'FAIL  ').$name, PHP_EOL;
};

$host = getenv('TLS_HOST');
$ip = getenv('TLS_IP');
$ca = getenv('TLS_CA');
$rounds = max(1, (int) getenv('ROUNDS'));
$http = new Factory;

// Webhook-delivery-shaped request: the validated IP is pinned with CURLOPT_RESOLVE,
// redirects are never followed, bounded connect/total timeouts.
$webhook = fn (string $url, array $extra = []) => $http
    ->withOptions(array_replace(['allow_redirects' => false, 'verify' => $ca,
        'curl' => [CURLOPT_RESOLVE => ["pinned.invalid:8443:{$ip}"]]], $extra))
    ->connectTimeout(3)->timeout(5)
    ->send('POST', $url, ['body' => '{"event":"smoke"}']);

$check('libcurl 8.22.0, OpenSSL, HTTP/2, HTTP(S) only', function () {
    $v = curl_version();

    return $v['version'] === '8.22.0' && str_starts_with($v['ssl_version'], 'OpenSSL/')
        && ($v['features'] & CURL_VERSION_HTTP2) && $v['protocols'] === ['http', 'https'];
});
$check('libxml2 2.15.4', fn () => LIBXML_DOTTED_VERSION === '2.15.4');

for ($round = 1; $round <= $rounds; $round++) {
    $last = $round === $rounds;
    $run = function (string $name, callable $test) use ($check, $last, &$failures): void {
        if ($last) {
            $check($name, $test);
        } else {
            try {
                $test() || $failures++;
            } catch (Throwable) {
                $failures++;
            }
        }
    };

    // --- HTTPS client (webhooks, integrations) ---------------------------------
    $run('HTTPS request with the pinned IP (CURLOPT_RESOLVE) verifies the certificate and succeeds', fn () => $webhook('https://pinned.invalid:8443/ok')->json('ok') === true);
    $run('a certificate from an untrusted CA is refused (system trust store)', function () use ($http, $host) {
        try {
            $http->connectTimeout(3)->timeout(5)->get("https://{$host}:8443/ok");
        } catch (ConnectionException $e) {
            return str_contains(strtolower($e->getMessage()), 'certificate');
        }

        return false;
    });
    $run('a hostname mismatch is refused', function () use ($http, $ip, $ca) {
        try {
            $http->withOptions(['verify' => $ca, 'curl' => [CURLOPT_RESOLVE => ["wrong.invalid:8443:{$ip}"]]])->connectTimeout(3)->timeout(5)->get('https://wrong.invalid:8443/ok');
        } catch (ConnectionException) {
            return true;
        }

        return false;
    });
    $run('redirects are never followed (3xx returned as-is)', fn () => $webhook('https://pinned.invalid:8443/redirect')->status() === 302);
    $run('the total timeout is enforced', function () use ($webhook) {
        $start = microtime(true);
        try {
            $webhook('https://pinned.invalid:8443/slow', []);
        } catch (ConnectionException) {
            return microtime(true) - $start < 8;
        }

        return false;
    });
    $run('a refused connection fails as a connection error', function () use ($http, $ip, $ca) {
        try {
            $http->withOptions(['verify' => $ca, 'curl' => [CURLOPT_RESOLVE => ["pinned.invalid:1:{$ip}"]]])->connectTimeout(2)->timeout(3)->get('https://pinned.invalid:1/');
        } catch (ConnectionException) {
            return true;
        }

        return false;
    });

    // --- S3 over HTTP with XML responses (Documents / attachments) ---------------
    $run('AWS SDK round trip against MinIO (create, put, list XML, get, error XML, delete)', function () {
        $s3 = new S3Client(['version' => 'latest', 'region' => 'us-east-1', 'endpoint' => getenv('S3_ENDPOINT'),
            'use_path_style_endpoint' => true, 'credentials' => ['key' => getenv('S3_KEY'), 'secret' => getenv('S3_SECRET')]]);
        $bucket = 'smoke-'.bin2hex(random_bytes(4));
        $s3->createBucket(['Bucket' => $bucket]);
        $s3->putObject(['Bucket' => $bucket, 'Key' => 'a/b.txt', 'Body' => 'native-smoke']);
        $listed = array_column($s3->listObjectsV2(['Bucket' => $bucket])['Contents'] ?? [], 'Key');
        $body = (string) $s3->getObject(['Bucket' => $bucket, 'Key' => 'a/b.txt'])['Body'];
        try {
            $s3->getObject(['Bucket' => $bucket, 'Key' => 'missing']);
            $missing = false;
        } catch (S3Exception $e) {
            $missing = $e->getAwsErrorCode() === 'NoSuchKey';
        }
        $s3->deleteObject(['Bucket' => $bucket, 'Key' => 'a/b.txt']);
        $s3->deleteBucket(['Bucket' => $bucket]);

        return $listed === ['a/b.txt'] && $body === 'native-smoke' && $missing;
    });

    // --- PHP XML stack -----------------------------------------------------------
    $xml = '<?xml version="1.0" encoding="UTF-8"?><r xmlns:x="urn:x"><item id="1">Ä&amp;b</item><item id="2"><x:n>two</x:n></item></r>';
    $run('DOM parse, XPath and serialization', function () use ($xml) {
        $d = new DOMDocument;
        $d->loadXML($xml);
        $xp = new DOMXPath($d);
        $xp->registerNamespace('x', 'urn:x');

        return $xp->evaluate('string(//item[@id="1"])') === 'Ä&b' && $xp->evaluate('string(//x:n)') === 'two'
            && str_contains($d->saveXML(), '<item id="2">');
    });
    $run('SimpleXML', fn () => (string) simplexml_load_string($xml)->item[0]['id'] === '1');
    $run('XMLReader streaming', function () use ($xml) {
        $r = XMLReader::XML($xml);
        $n = 0;
        while ($r->read()) {
            $n += (int) ($r->nodeType === XMLReader::ELEMENT && $r->localName === 'item');
        }

        return $n === 2;
    });
    $run('XMLWriter', function () {
        $w = new XMLWriter;
        $w->openMemory();
        $w->startDocument('1.0', 'UTF-8');
        $w->writeElement('a', 'x<y');

        return str_contains($w->outputMemory(), '<a>x&lt;y</a>');
    });
    $run('external entities are not loaded by default (XXE)', function () {
        $d = new DOMDocument;
        @$d->loadXML('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY e SYSTEM "file:///etc/passwd">]><r>&e;</r>');

        return ! str_contains((string) $d->textContent, 'root:');
    });
    $run('malformed XML reports structured errors', function () {
        libxml_use_internal_errors(true);
        $ok = simplexml_load_string('<a><b></a>') === false && count(libxml_get_errors()) > 0;
        libxml_clear_errors();

        return $ok;
    });
    $run('mail CSS inlining (css-to-inline-styles, DOM)', fn () => str_contains(
        (new CssToInlineStyles)->convert('<html><body><p class="x">hi</p></body></html>', '.x { color: red; }'),
        'style="color: red;"'
    ));
}

echo $failures === 0 ? "native-smoke: all checks passed ({$rounds} rounds)" : "native-smoke: {$failures} failure(s)", PHP_EOL;
exit($failures === 0 ? 0 : 1);
