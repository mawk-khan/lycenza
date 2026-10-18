<?php

namespace Tests\Support\Authorization;

use PhpToken;
use ReflectionMethod;

/**
 * ORG.2 (ADR 0076 §28): the calls one PHP method makes, read from its
 * tokens (comments dropped), each marked unconditional or not. A call is
 * unconditional when it is not inside an if/else/loop/switch/match/catch
 * block and its statement has no short-circuit or ternary before it --
 * closures and try blocks do not make a call conditional (a helper such
 * as staffJson()/command() runs the closure it is given).
 *
 * Deliberately small: it is a drift detector for declared coverage, not a
 * PHP interpreter.
 */
final class SourceCalls
{
    private const CONDITIONAL = [T_IF, T_ELSEIF, T_ELSE, T_FOREACH, T_FOR, T_WHILE, T_DO, T_SWITCH, T_CATCH, T_MATCH];

    private const SHORT_CIRCUIT = ['&&', '||', '?', '??', 'and', 'or', 'xor', '?->'];

    /** @var array<string, list<PhpToken>> */
    private static array $files = [];

    /**
     * @return list<array{receiver: string, name: string, args: string, unconditional: bool}>
     */
    public static function of(ReflectionMethod $method): array
    {
        $tokens = self::body($method);
        $calls = [];
        $stack = [];
        $statementStart = 0;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];

            if ($t->text === '{') {
                $stack[] = self::opensConditional($tokens, $i);
                $statementStart = $i + 1;

                continue;
            }
            if ($t->text === '}') {
                array_pop($stack);
                $statementStart = $i + 1;

                continue;
            }
            if ($t->text === ';') {
                $statementStart = $i + 1;

                continue;
            }
            if ($t->text === '${') {
                $stack[] = false;

                continue;
            }
            if (! $t->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }

            $next = $i + 1;
            if (! isset($tokens[$next]) || $tokens[$next]->text !== '(') {
                continue;
            }

            $prev = $i - 1;
            $receiver = '';
            if ($prev >= 0 && in_array($tokens[$prev]->text, ['->', '::', '?->'], true)) {
                $receiver = self::receiver($tokens, $prev);
            } elseif ($prev >= 0 && $tokens[$prev]->is([T_FUNCTION, T_NEW])) {
                continue; // a declaration, not a call
            }

            $close = self::matching($tokens, $next);
            $calls[] = [
                'receiver' => $receiver,
                'name' => $t->text,
                'args' => self::text($tokens, $next + 1, $close - 1),
                'unconditional' => ! in_array(true, $stack, true) && ! self::shortCircuited($tokens, $statementStart, $i),
            ];
        }

        return $calls;
    }

    /** The method's source without comments, for symbol checks. */
    public static function source(ReflectionMethod $method): string
    {
        return self::text(self::body($method), 0, count(self::body($method)) - 1);
    }

    /** @return list<PhpToken> */
    private static function body(ReflectionMethod $method): array
    {
        $file = (string) $method->getFileName();
        if ($file === '') {
            return [];
        }
        self::$files[$file] ??= array_values(array_filter(
            PhpToken::tokenize((string) file_get_contents($file)),
            fn (PhpToken $t) => ! $t->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE]),
        ));

        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();
        $inRange = array_values(array_filter(self::$files[$file], fn (PhpToken $t) => $t->line >= $start && $t->line <= $end));

        // From the method's own opening brace to its matching close.
        foreach ($inRange as $i => $t) {
            if ($t->is(T_FUNCTION)) {
                $nameAt = $i + 1;
                if (isset($inRange[$nameAt]) && $inRange[$nameAt]->text === $method->getName()) {
                    for ($j = $nameAt; $j < count($inRange); $j++) {
                        if ($inRange[$j]->text === '{') {
                            return array_slice($inRange, $j + 1, self::matching($inRange, $j) - $j - 1);
                        }
                    }
                }
            }
        }

        return [];
    }

    /** @param list<PhpToken> $tokens */
    private static function opensConditional(array $tokens, int $brace): bool
    {
        $depth = 0;
        for ($i = $brace - 1; $i >= 0; $i--) {
            $text = $tokens[$i]->text;
            if ($text === ')') {
                $depth++;
            } elseif ($text === '(') {
                $depth--;
            } elseif ($depth === 0) {
                if (in_array($text, [';', '{', '}'], true)) {
                    return false;
                }
                if ($tokens[$i]->is(self::CONDITIONAL)) {
                    return true;
                }
                if ($tokens[$i]->is([T_FUNCTION, T_FN, T_TRY, T_FINALLY, T_CLASS])) {
                    return false;
                }
            }
        }

        return false;
    }

    /** @param list<PhpToken> $tokens */
    private static function shortCircuited(array $tokens, int $from, int $to): bool
    {
        $depth = 0;
        for ($i = $from; $i < $to; $i++) {
            $text = strtolower($tokens[$i]->text);
            if ($text === '(' || $text === '[') {
                $depth++;
            } elseif ($text === ')' || $text === ']') {
                $depth--;
            }
            // An operator before the call in the same statement -- inside
            // or outside parentheses -- means the call may not run.
            if (in_array($text, self::SHORT_CIRCUIT, true) || $tokens[$i]->is([T_FN, T_MATCH])) {
                return true;
            }
        }

        return false;
    }

    /** @param list<PhpToken> $tokens */
    private static function receiver(array $tokens, int $operator): string
    {
        $parts = [];
        $depth = 0;
        for ($i = $operator - 1; $i >= 0; $i--) {
            $text = $tokens[$i]->text;
            if ($text === ')') {
                $depth++;
            } elseif ($text === '(') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($depth === 0 && ! $tokens[$i]->is([T_VARIABLE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_STATIC, T_CLASS])) {
                break;
            }
            array_unshift($parts, $text);
        }

        return implode('', $parts);
    }

    /** @param list<PhpToken> $tokens */
    private static function matching(array $tokens, int $open): int
    {
        $curly = $tokens[$open]->text === '{';
        $depth = 0;
        for ($i = $open; $i < count($tokens); $i++) {
            $text = $tokens[$i]->text;
            if ($curly ? in_array($text, ['{', '${'], true) : $text === $tokens[$open]->text) {
                $depth++;
            } elseif ($text === ($curly ? '}' : ($tokens[$open]->text === '(' ? ')' : ']'))) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return count($tokens) - 1;
    }

    /** @param list<PhpToken> $tokens */
    private static function text(array $tokens, int $from, int $to): string
    {
        $out = '';
        for ($i = $from; $i <= $to && isset($tokens[$i]); $i++) {
            $out .= $tokens[$i]->text.' ';
        }

        return trim($out);
    }
}
