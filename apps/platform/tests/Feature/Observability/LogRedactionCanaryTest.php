<?php

namespace Tests\Feature\Observability;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CapturesStructuredLogs;
use Tests\TestCase;

/**
 * Phase 0O.5A (ADR 0051 §6, brief step 68): fake secrets planted in every
 * place they could come from -- request body, Authorization header, a
 * QueryException binding, URL userinfo, a stack-frame argument, partner and
 * Sanctum tokens, an APP_KEY-shaped value, the AI signing key -- then REAL
 * logging paths triggered. No canary may appear in the captured output.
 */
class LogRedactionCanaryTest extends TestCase
{
    use CapturesStructuredLogs;

    private const BODY = 'canary-body-password-1a2b';

    private const AUTH = 'canary-auth-header-3c4d';

    private const SQL = 'canary-sql-value-5e6f';

    private const URL_SECRET = 'canary-url-pass-7a8b';

    private const ARG = 'CANARYARG77';

    private const PARTNER = 'lyc_pk_0123456789abcdef.canarypartnersecret9c0d';

    private const SANCTUM = '17|lyc_pat_canarysanctumtoken1e2f';

    private const APP_KEY = 'base64:Q0FOQVJZQVBQS0VZQ0FOQVJZQVBQS0VZMTIzNDU2Nzg=';

    private const SIGNING = 'canary-signing-key-3a4b5c6d';

    #[Test]
    public function no_planted_secret_survives_any_real_logging_path(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        ini_set('zend.exception_string_param_max_len', '15');
        $this->captureLogs();

        // 1-2. A request whose body and Authorization header carry secrets,
        // and whose handler fails unexpectedly (framework reporter path).
        Route::post('/_canary-failing', function () {
            Log::info('canary.request.received', ['payload' => request()->all(), 'authorization' => request()->header('Authorization')]);

            throw new \RuntimeException('handler failed for '.request()->input('password'));
        });
        $this->withHeaders(['Authorization' => 'Bearer '.self::AUTH])
            ->post('http://localhost/_canary-failing', ['password' => self::BODY, 'note' => 'plain'])
            ->assertStatus(500);

        // 3. A QueryException with a bound value.
        try {
            DB::transaction(fn () => DB::select('select ?::integer', [self::SQL]));
        } catch (QueryException $e) {
            report($e);
        }

        // 4. URL userinfo, 6-9. tokens and keys in free text and context.
        Log::warning('upstream https://svc:'.self::URL_SECRET.'@objects.example.net/bucket failed', [
            'partner' => 'presented '.self::PARTNER,
            'note' => 'token '.self::SANCTUM,
            'config_dump' => ['APP_KEY' => self::APP_KEY, 'ai_signing_key' => self::SIGNING, 'key_material' => self::APP_KEY],
        ]);

        // 5. A stack frame whose argument is secret.
        try {
            $this->explode(self::ARG);
        } catch (\RuntimeException $e) {
            report($e);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }

        $this->assertNoCanaryLogged(
            self::BODY, self::AUTH, self::SQL, self::URL_SECRET, self::ARG,
            'canarypartnersecret9c0d', 'canarysanctumtoken1e2f', 'Q0FOQVJZQVBQS0VZ', self::SIGNING,
        );

        // Diagnostic value is kept.
        $events = array_column($this->capturedJson(), 'event_code');
        $this->assertContains('canary.request.received', $events);
        $this->assertContains('application.exception', $events);
        $this->assertContains(QueryException::class, array_column($this->capturedJson(), 'exception_class'));
        $this->assertContains('22P02', array_column($this->capturedJson(), 'sqlstate'));
        $this->assertStringContainsString('lyc_pk_[redacted]', $this->capturedOutput());
    }

    private function explode(string $secret): never
    {
        throw new \RuntimeException('frame argument test');
    }
}
