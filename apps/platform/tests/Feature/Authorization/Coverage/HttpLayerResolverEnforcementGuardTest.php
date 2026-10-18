<?php

namespace Tests\Feature\Authorization\Coverage;

use Illuminate\Support\Facades\File;
use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ORG.2 (ADR 0076 §28): at the HTTP boundary a School or platform
 * capability REFUSAL goes through the canonical `capability` Gate
 * (`capability:` middleware, authorizeCapability(), Gate::authorize() /
 * Gate::denies()) -- the Gate is implemented by CapabilityResolver, and a
 * controller never re-implements it by refusing on a raw resolver answer.
 *
 * A controller may still READ the resolver to shape a response (a `canX`
 * flag, whether to include a sub-section): that is projection, never the
 * route's authorization. Group capabilities are the exception by rule 84
 * (`canInGroup()` is their only resolution), and the internal AI
 * authorization oracle answers "does this actor hold X" by design.
 */
class HttpLayerResolverEnforcementGuardTest extends TestCase
{
    private const RESOLVER_CALL = '/->\s*(canInSchool|canPlatform|can|schoolCapabilities|platformCapabilities)\s*\(/';

    /** ADR 0023: the internal AI completion authorization endpoint IS a capability oracle. */
    private const ALLOWED = [
        'app/Http/Controllers/Api/Internal/AiCompletionAuthorizationController.php',
    ];

    #[Test]
    public function no_http_layer_code_refuses_on_a_raw_resolver_answer(): void
    {
        $offenders = [];

        foreach ($this->httpFiles() as $relative => $file) {
            if (in_array($relative, self::ALLOWED, true)) {
                continue;
            }
            foreach ($this->methodBodies($file) as $source) {
                $this->inspect($relative, $source, $offenders);
            }
        }

        $this->assertSame([], $offenders, "Refuse through the `capability` Gate, not a raw CapabilityResolver answer:\n");
    }

    /** @param list<string> $offenders */
    private function inspect(string $relative, string $source, array &$offenders): void
    {
        // abort_unless(... resolver ...) / abort_if(... resolver ...)
        foreach ($this->callArguments($source, ['abort_unless', 'abort_if']) as $args) {
            if (preg_match(self::RESOLVER_CALL, $args) || $this->mentionsResolverVariable($source, $args)) {
                $offenders[] = "{$relative}: abort_*({$this->short($args)})";
            }
        }

        // if (... resolver ...) { throw ... / abort(...) }
        if (preg_match_all('/\bif\s*(\((?:[^()]++|(?1))*\))\s*\{([^{}]*)\}/', $source, $ifs, PREG_SET_ORDER)) {
            foreach ($ifs as [, $condition, $block]) {
                $refuses = preg_match('/\bthrow\b|\babort\s*\(/', $block) === 1;
                if ($refuses && (preg_match(self::RESOLVER_CALL, $condition) || $this->mentionsResolverVariable($source, $condition))) {
                    $offenders[] = "{$relative}: if {$this->short($condition)} refuses";
                }
            }
        }
    }

    /** @return list<string> each function body of the file (variables are method-scoped) */
    private function methodBodies(string $source): array
    {
        preg_match_all('/\bfunction\s+\w+\s*(\((?:[^()]++|(?1))*\))[^{;]*(\{(?:[^{}]++|(?2))*\})/', $source, $matches);

        return $matches[2];
    }

    /** @return array<string, string> relative path => source without comments */
    private function httpFiles(): array
    {
        $files = [];
        $roots = [app_path('Http'), ...glob(app_path('Domain/*/Http'), GLOB_ONLYDIR) ?: []];

        foreach ($roots as $root) {
            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $code = '';
                foreach (PhpToken::tokenize($file->getContents()) as $token) {
                    if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
                        $code .= $token->text;
                    }
                }
                $files[str_replace(base_path().'/', '', $file->getPathname())] = $code;
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @param  list<string>  $functions
     * @return list<string>
     */
    private function callArguments(string $source, array $functions): array
    {
        $pattern = '/\b(?:'.implode('|', $functions).')\s*(\((?:[^()]++|(?1))*\))/';
        preg_match_all($pattern, $source, $matches);

        return $matches[1];
    }

    /** The expression names a variable that the same file assigns from a resolver call. */
    private function mentionsResolverVariable(string $source, string $expression): bool
    {
        preg_match_all('/(\$\w+)\s*=\s*[^;]*?'.trim(self::RESOLVER_CALL, '/').'/', $source, $assigned);

        foreach (array_unique($assigned[1]) as $variable) {
            if (preg_match('/'.preg_quote($variable, '/').'\b/', $expression)) {
                return true;
            }
        }

        return false;
    }

    private function short(string $text): string
    {
        return mb_strimwidth((string) preg_replace('/\s+/', ' ', $text), 0, 120, '...');
    }
}
