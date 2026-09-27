<?php

namespace App\Support\Domains\Probe;

use Carbon\CarbonImmutable;

/**
 * Production DomainProber (ADR 0054 section 6.2), on PHP's OpenSSL streams.
 *
 * For each routing-validated edge address (at most two tried, IP-pinned --
 * the hostname is never resolved again here):
 *  1. TCP connect to <address>:443 (5 s);
 *  2. TLS with SNI = hostname, peer and peer-name verification against the
 *     system public CA bundle (OpenSSL's default store), TLS 1.2 or 1.3
 *     only, no self-signed certificates -- there is no switch that relaxes
 *     any of this; a test may only name a different CA FILE;
 *  3. the negotiated protocol and the leaf's validity period re-checked;
 *  4. `GET /.well-known/lycenza-domain-probe?n=<fresh nonce>` over HTTP/1.0
 *     on that connection (5 s read, <= 8 KiB read, redirects never
 *     followed), and the body compared with ProbeProof.
 * Only public certificate facts are returned (notAfter, SHA-256
 * fingerprint, issuer name). A connect or read failure is indeterminate;
 * any TLS failure is tls_invalid; a TLS-valid answer that is not the proof
 * is proof_mismatch.
 */
final class StreamDomainProber implements DomainProber
{
    public const CONNECT_TIMEOUT_SECONDS = 5.0;

    public const READ_TIMEOUT_SECONDS = 5;

    public const MAX_RESPONSE_BYTES = 8192;

    public const MAX_ADDRESSES = 2;

    public function __construct(
        private readonly ProbeProof $proof,
        private readonly int $port = 443,
        private readonly ?string $caFile = null,
    ) {}

    public function probe(string $hostname, array $addresses): ProbeResult
    {
        $result = new ProbeResult(ProbeResult::INDETERMINATE, 'no_address');

        foreach (array_slice($addresses, 0, self::MAX_ADDRESSES) as $address) {
            $result = $this->probeAddress($hostname, $address);

            if ($result->outcome !== ProbeResult::INDETERMINATE) {
                return $result;
            }
        }

        return $result;
    }

    private function probeAddress(string $hostname, string $address): ProbeResult
    {
        $nonce = ProbeProof::nonce();

        $ssl = [
            'peer_name' => $hostname,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'capture_peer_cert' => true,
            'disable_compression' => true,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
        if ($this->caFile !== null) {
            $ssl['cafile'] = $this->caFile;
        }

        $target = (str_contains($address, ':') ? "[{$address}]" : $address).':'.$this->port;
        $socket = @stream_socket_client('tcp://'.$target, $errno, $errstr, self::CONNECT_TIMEOUT_SECONDS, STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => $ssl]));

        if ($socket === false) {
            return new ProbeResult(ProbeResult::INDETERMINATE, 'connect');
        }

        try {
            stream_set_timeout($socket, self::READ_TIMEOUT_SECONDS);
            error_clear_last();

            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT) !== true) {
                $message = strtolower((string) (error_get_last()['message'] ?? ''));

                return str_contains($message, 'timed out') || stream_get_meta_data($socket)['timed_out']
                    ? new ProbeResult(ProbeResult::INDETERMINATE, 'timeout')
                    : new ProbeResult(ProbeResult::TLS_INVALID, $this->tlsReason($message));
            }

            $meta = stream_get_meta_data($socket);
            if (! in_array($meta['crypto']['protocol'] ?? '', ['TLSv1.2', 'TLSv1.3'], true)) {
                return new ProbeResult(ProbeResult::TLS_INVALID, 'protocol');
            }

            $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
            $info = $certificate !== null ? openssl_x509_parse($certificate) : false;
            if (! is_array($info) || ! isset($info['validFrom_time_t'], $info['validTo_time_t'])) {
                return new ProbeResult(ProbeResult::TLS_INVALID, 'handshake');
            }

            $now = time();
            if ((int) $info['validTo_time_t'] <= $now) {
                return new ProbeResult(ProbeResult::TLS_INVALID, 'expired');
            }
            if ((int) $info['validFrom_time_t'] > $now) {
                return new ProbeResult(ProbeResult::TLS_INVALID, 'not_yet_valid');
            }

            $notAfter = CarbonImmutable::createFromTimestampUTC((int) $info['validTo_time_t']);
            $fingerprint = openssl_x509_fingerprint($certificate, 'sha256') ?: null;
            $issuerName = $info['issuer']['O'] ?? $info['issuer']['CN'] ?? null;
            $issuer = is_string($issuerName) ? mb_substr($issuerName, 0, 255) : null;

            $request = 'GET /'.ProbeProof::PATH.'?n='.$nonce." HTTP/1.0\r\nHost: {$hostname}\r\nUser-Agent: lycenza-domain-probe\r\nAccept: text/plain\r\nConnection: close\r\n\r\n";
            if (@fwrite($socket, $request) === false) {
                return new ProbeResult(ProbeResult::INDETERMINATE, 'connect');
            }

            $response = '';
            while (! feof($socket) && strlen($response) < self::MAX_RESPONSE_BYTES) {
                $chunk = fread($socket, self::MAX_RESPONSE_BYTES - strlen($response));
                if ($chunk === false || $chunk === '') {
                    if (stream_get_meta_data($socket)['timed_out']) {
                        return new ProbeResult(ProbeResult::INDETERMINATE, 'timeout');
                    }
                    break;
                }
                $response .= $chunk;
            }

            [$head, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');

            if (preg_match('#^HTTP/1\.[01] 200 #', $head) !== 1) {
                return new ProbeResult(ProbeResult::PROOF_MISMATCH, 'status', $notAfter, $fingerprint, $issuer);
            }

            return $this->proof->matches($hostname, $nonce, $body)
                ? new ProbeResult(ProbeResult::PASS, 'ok', $notAfter, $fingerprint, $issuer)
                : new ProbeResult(ProbeResult::PROOF_MISMATCH, 'proof', $notAfter, $fingerprint, $issuer);
        } finally {
            fclose($socket);
        }
    }

    private function tlsReason(string $message): string
    {
        return match (true) {
            str_contains($message, 'did not match expected') || str_contains($message, 'peer certificate cn') => 'hostname_mismatch',
            str_contains($message, 'expired') => 'expired',
            str_contains($message, 'not yet valid') => 'not_yet_valid',
            str_contains($message, 'certificate verify failed') || str_contains($message, 'self-signed') || str_contains($message, 'self signed') => 'untrusted',
            str_contains($message, 'version') || str_contains($message, 'protocol') => 'protocol',
            default => 'handshake',
        };
    }
}
