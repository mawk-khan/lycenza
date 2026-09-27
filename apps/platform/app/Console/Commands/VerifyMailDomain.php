<?php

namespace App\Console\Commands;

use App\Support\Email\Providers\EmailProviderResolver;
use App\Support\Email\SendingDomainEvidence;
use Illuminate\Console\Command;

/**
 * Phase 0O.9A (ADR 0055 sections 17-18): read-only DNS evidence for the
 * configured sending domain -- SPF on the return path, the DKIM selector
 * key, the effective DMARC policy -- through the bounded 0O.8A resolver.
 * It never changes DNS and never claims what it cannot prove: provider
 * domain verification and the 14-day aligned-DKIM history are reported as
 * deployment evidence. Exit 0 only when SPF and DKIM pass and DMARC is at
 * least `quarantine` for the whole stream.
 */
class VerifyMailDomain extends Command
{
    protected $signature = 'platform:mail-verify-domain {--json : Machine-readable output}';

    protected $description = 'Check the sending domain\'s SPF, DKIM and DMARC evidence (ADR 0055; read-only).';

    public function handle(SendingDomainEvidence $evidence, EmailProviderResolver $providers): int
    {
        $results = $evidence->evaluate();
        $results['sending_domain_reserved'] = ['result' => $providers->sendingDomainReserved() ? 'reserved' : 'not_reserved', 'detail' => 'no School can claim it as a web domain'];
        $ready = SendingDomainEvidence::dnsReady($results) && $results['sending_domain_reserved']['result'] === 'reserved';

        if ($this->option('json')) {
            $this->line((string) json_encode(['dns_ready' => $ready, 'checks' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($results as $check => $result) {
                $this->line(sprintf('%-30s %s%s', $check, $result['result'], $result['detail'] !== null ? "  ({$result['detail']})" : ''));
            }
            $this->line($ready ? 'DNS evidence: READY (provider verification and DMARC history remain deployment evidence)' : 'DNS evidence: NOT READY');
        }

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
