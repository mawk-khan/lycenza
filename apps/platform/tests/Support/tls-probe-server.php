<?php

// Phase 0O.8A (ADR 0054 section 6.2): a LOCAL TLS "edge" for
// StreamDomainProberTest -- loopback only, throwaway test certificates.
// Writes its port to <portfile>, then answers GET
// /.well-known/lycenza-domain-probe?n=<nonce> per <mode> until killed.
//
// Usage: php tls-probe-server.php <portfile> <cert> <key> <mode> <probeKey>
//   mode: proof | wrong-proof | not-found | redirect | old-tls | hang

[, $portFile, $cert, $key, $mode, $probeKey] = $argv;

if ($mode === 'hang') {
    // Accepts TCP and never speaks TLS: the client must time out.
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
} else {
    $context = stream_context_create(['ssl' => [
        'local_cert' => $cert,
        'local_pk' => $key,
        'verify_peer' => false,
        'crypto_method' => $mode === 'old-tls'
            ? STREAM_CRYPTO_METHOD_TLSv1_0_SERVER | STREAM_CRYPTO_METHOD_TLSv1_1_SERVER
            : STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER,
        'ciphers' => $mode === 'old-tls' ? 'DEFAULT@SECLEVEL=0' : 'DEFAULT',
    ]]);
    $server = stream_socket_server('ssl://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
}

if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
file_put_contents($portFile, (string) parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT));

$held = [];
while (true) {
    $client = @stream_socket_accept($server, 60);
    if ($client === false) {
        continue; // failed handshakes (untrusted, too old, ...) end up here
    }
    if ($mode === 'hang') {
        $held[] = $client;

        continue;
    }

    $request = '';
    while (! str_contains($request, "\r\n\r\n") && ($line = fgets($client)) !== false) {
        $request .= $line;
    }
    preg_match('#^GET (\S+) HTTP#', $request, $m);
    preg_match('/^Host:\s*(\S+)/mi', $request, $h);
    parse_str((string) parse_url($m[1] ?? '/', PHP_URL_QUERY), $query);
    $host = strtolower($h[1] ?? '');
    $nonce = (string) ($query['n'] ?? '');

    [$status, $body, $extra] = match ($mode) {
        'proof' => ['200 OK', hash_hmac('sha256', $host.'|'.$nonce, $probeKey), ''],
        'wrong-proof' => ['200 OK', hash_hmac('sha256', $host.'|'.$nonce, 'another-deployment-key'), ''],
        'not-found' => ['404 Not Found', 'Not Found', ''],
        'redirect' => ['308 Permanent Redirect', '', "Location: https://elsewhere.example/\r\n"],
        default => ['500 Internal Server Error', '', ''],
    };

    fwrite($client, "HTTP/1.0 {$status}\r\nContent-Type: text/plain\r\n{$extra}Content-Length: ".strlen($body)."\r\n\r\n{$body}");
    fclose($client);
}
