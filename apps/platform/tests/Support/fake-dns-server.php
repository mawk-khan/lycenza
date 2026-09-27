<?php

// Phase 0O.8A (ADR 0054 section 4.5): a tiny LOCAL DNS responder for
// NetDns2DomainResolverTest -- UDP on 127.0.0.1, one fixed scenario per query
// name, real DNS wire format, never any network beyond loopback. Writes its
// port to <portfile>, then answers until killed.
//
// Usage: php fake-dns-server.php <portfile> <token>

[, $portFile, $token] = $argv;

$server = stream_socket_server('udp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr}\n");
    exit(1);
}
file_put_contents($portFile, (string) parse_url('udp://'.stream_socket_get_name($server, false), PHP_URL_PORT));

function name(string $name): string
{
    $out = '';
    foreach (explode('.', rtrim($name, '.')) as $label) {
        $out .= chr(strlen($label)).$label;
    }

    return $out."\0";
}

function rr(string $owner, int $type, string $rdata): string
{
    return name($owner).pack('nnNn', $type, 1, 60, strlen($rdata)).$rdata;
}

function txt(string $owner, array $strings): string
{
    return rr($owner, 16, implode('', array_map(fn ($s) => chr(strlen($s)).$s, $strings)));
}

function cname(string $owner, string $target): string
{
    return rr($owner, 5, name($target));
}

function a(string $owner, string $ip): string
{
    return rr($owner, strlen(inet_pton($ip)) === 4 ? 1 : 28, inet_pton($ip));
}

$value = 'lycenza-domain-verification='.$token;

while (true) {
    $packet = stream_socket_recvfrom($server, 1500, 0, $peer);
    if ($packet === false || strlen($packet) < 12) {
        continue;
    }

    $id = substr($packet, 0, 2);
    $offset = 12;
    $labels = [];
    while (($len = ord($packet[$offset])) !== 0) {
        $labels[] = substr($packet, $offset + 1, $len);
        $offset += $len + 1;
    }
    $question = substr($packet, 12, $offset + 5 - 12);
    $qtype = unpack('n', substr($packet, $offset + 1, 2))[1];
    $qname = strtolower(implode('.', $labels));

    $rcode = 0;
    $answers = [];

    switch ($qname) {
        case 'txt-ok.test':
            $answers = $qtype === 16 ? [txt($qname, [$value])] : [];
            break;
        case 'txt-split.test':
            $answers = $qtype === 16 ? [txt($qname, ['lycenza-domain-', 'verification='.$token])] : [];
            break;
        case 'txt-multi.test':
            $answers = $qtype === 16 ? [txt($qname, ['v=spf1 -all']), txt($qname, [$value.'x']), txt($qname, ['other'])] : [];
            break;
        case 'txt-many.test':
            // 33 one-byte RRs, owner name compressed (0xC00C): fits one UDP packet.
            $answers = $qtype === 16 ? array_map(fn ($i) => "\xC0\x0C".pack('nnNn', 16, 1, 60, 2).chr(1).chr(97 + $i % 26), range(0, 32)) : [];
            break;
        case 'txt-via-cname.test':
            $answers = $qtype === 16 ? [cname($qname, 'txt-target.test'), txt('txt-target.test', [$value])] : [];
            break;
        case 'nodata.test':
            break;
        case 'servfail.test':
            $rcode = 2;
            break;
        case 'refused.test':
            $rcode = 5;
            break;
        case 'silent.test':
            continue 2; // never answer: the client must time out
        case 'apex.test':
            $answers = match ($qtype) {
                1 => [a($qname, '1.2.3.4'), a($qname, '1.2.3.5')],
                28 => [a($qname, '2606:4700::6810:84e5')],
                default => [],
            };
            break;
        case 'cname.test':
            $answers = match ($qtype) {
                1 => [cname($qname, 'edge.lycenza-cdn.test'), a('edge.lycenza-cdn.test', '1.2.3.4')],
                28 => [cname($qname, 'edge.lycenza-cdn.test')],
                default => [],
            };
            break;
        case 'loop.test':
            $answers = [cname($qname, 'loop-b.test'), cname('loop-b.test', 'loop.test')];
            break;
        case 'deep.test':
            $chain = array_map(fn ($i) => $i === 0 ? 'deep.test' : "d{$i}.test", range(0, 10));
            foreach (range(0, 9) as $i) {
                $answers[] = cname($chain[$i], $chain[$i + 1]);
            }
            $answers[] = a($chain[10], '1.2.3.4');
            break;
        default:
            $rcode = 3; // NXDOMAIN
    }

    $header = $id.pack('nnnnn', 0x8180 | $rcode, 1, count($answers), 0, 0);
    stream_socket_sendto($server, $header.$question.implode('', $answers), 0, $peer);
}
