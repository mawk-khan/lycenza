<?php

// Phase 0O.9A (ADR 0055 section 6): a LOCAL SMTP peer for
// SmtpEmailProviderTest -- loopback only, plaintext, one scripted
// behaviour. Writes its port to <portfile>, appends every command verb (and
// the DATA payload) to <logfile>, and serves connections until killed.
//
// Usage: php smtp-test-server.php <portfile> <logfile> <mode>
//   mode: accept | reject-recipient | auth-fail | greylist | hang

[, $portFile, $logFile, $mode] = $argv;

$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "listen failed: {$errstr}\n");
    exit(1);
}
file_put_contents($portFile, (string) parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT));

$log = fn (string $line) => file_put_contents($logFile, $line."\n", FILE_APPEND);
$held = [];

while (true) {
    $client = @stream_socket_accept($server, 60);
    if ($client === false) {
        continue;
    }
    if ($mode === 'hang') {
        $held[] = $client; // never greets: the client must time out

        continue;
    }

    $send = fn (string $line) => fwrite($client, $line."\r\n");
    $send('220 lycenza-test-smtp ESMTP');
    $inData = false;
    $data = '';

    while (($line = fgets($client)) !== false) {
        if ($inData) {
            if (rtrim($line, "\r\n") === '.') {
                $inData = false;
                $log('DATA-BODY '.base64_encode($data));
                $send('250 Ok: queued as TESTQ42');

                continue;
            }
            $data .= $line;

            continue;
        }

        $verb = strtoupper(strtok(trim($line), ' :'));
        $log($verb);

        match ($verb) {
            'EHLO' => $mode === 'auth-fail'
                ? $send("250-lycenza-test-smtp\r\n250 AUTH PLAIN LOGIN")
                : $send('250 lycenza-test-smtp'),
            'HELO' => $send('250 lycenza-test-smtp'),
            'AUTH' => $send('535 5.7.8 Authentication credentials invalid'),
            'MAIL' => $mode === 'greylist' ? $send('451 4.7.1 Try again later') : $send('250 Ok'),
            'RCPT' => $mode === 'reject-recipient' ? $send('550 5.1.1 No such user') : $send('250 Ok'),
            'DATA' => ($inData = true) && $send('354 End data with <CR><LF>.<CR><LF>'),
            'RSET', 'NOOP' => $send('250 Ok'),
            'QUIT' => $send('221 Bye'),
            default => $send('502 Command not implemented'),
        };

        if ($verb === 'QUIT') {
            break;
        }
    }
    fclose($client);
}
