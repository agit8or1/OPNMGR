<?php
/**
 * Static analysis for tests/role_gate_test.php: does every state-changing
 * statement in a page run only after a role check?
 *
 * Works on PHP tokens, not regexes over the whole file, because "the file
 * mentions can( somewhere" is not a gate: before 3.80.3 the test accepted
 * exactly that, and so passed pages whose role check guarded one branch while
 * another branch wrote, and skipped pages that signed users in with
 * $_SESSION['user_id'] instead of requireLogin().
 *
 * Rules:
 *  - A gate is a call to require_permission() or requireAdmin(), or an
 *    `if (!can(...))` / `if (!isAdmin())` guard whose block is flat and exits.
 *    A CLI-only guard (`if (PHP_SAPI !== 'cli') { ... exit; }` or
 *    opnmgr_block_direct_web_access()), a web-only gate (`if (PHP_SAPI !==
 *    'cli') { require_permission(...); ...}`, the first statement of the
 *    block) and agent authentication (authenticateAgentRequest()) are
 *    recorded as such.
 *  - A gate covers what follows it in its own block and every block nested
 *    inside, so `if (POST) { require_permission(...); UPDATE ... }` passes and
 *    `if (a) { require_permission(...); } if (b) { UPDATE ... }` does not.
 *  - Writes are SQL INSERT/UPDATE/DELETE/REPLACE in string literals, shell
 *    execution, command queueing, and calls to functions declared in the same
 *    file that contain any of those. Function bodies are not walked as main
 *    flow: what matters is whether the call site is gated.
 *
 * Known limit: a gate inside one `case` of a switch also covers later cases.
 * Pages that dispatch on an action declare one top-level
 * require_permission(<expression>) instead (see api/tunnel_management.php).
 */

if (!function_exists('rg_analyze')) {
    /**
     * @return array{writes:int[], ungated:int[], gates:string[]}
     *         line numbers of every main-flow write, of the ungated ones, and
     *         the text of every gate in main-flow order
     */
    function rg_analyze(string $src): array {
        $toks = token_get_all($src);
        $n    = count($toks);

        // Pass 1: function and class bodies - which functions write, and which
        // token ranges are not main flow.
        $inBody  = array_fill(0, $n, false);
        $writers = [];
        for ($i = 0; $i < $n; $i++) {
            if (!is_array($toks[$i]) || !in_array($toks[$i][0], [T_FUNCTION, T_CLASS, T_TRAIT, T_INTERFACE], true)) {
                continue;
            }
            $name = null;
            for ($j = $i + 1; $j < $n && $toks[$j] !== '{' && $toks[$j] !== ';'; $j++) {
                if ($name === null && is_array($toks[$j]) && $toks[$j][0] === T_STRING) {
                    $name = $toks[$j][1];
                }
            }
            if ($j >= $n || $toks[$j] === ';') {   // abstract / interface method
                continue;
            }
            $depth = 0;
            $writes = false;
            for ($k = $j; $k < $n; $k++) {
                if (rg_opens($toks[$k])) { $depth++; }
                elseif ($toks[$k] === '}') { $depth--; }
                if (rg_is_write($toks, $k, [])) { $writes = true; }
                if ($depth === 0) { break; }
            }
            for ($m = $i; $m <= $k && $m < $n; $m++) { $inBody[$m] = true; }
            if ($writes && $name !== null && $toks[$i][0] === T_FUNCTION) {
                $writers[strtolower($name)] = true;
            }
            $i = $k;
        }

        // Pass 2: main flow, with a stack of "this block is gated" flags.
        $stack   = [false];
        $writes  = [];
        $ungated = [];
        $gates   = [];
        for ($i = 0; $i < $n; $i++) {
            if ($inBody[$i]) { continue; }
            $t = $toks[$i];
            if (rg_opens($t)) { $stack[] = false; continue; }
            if ($t === '}') { if (count($stack) > 1) { array_pop($stack); } continue; }

            if (is_array($t) && $t[0] === T_STRING && rg_next($toks, $i) === '('
                && !rg_prev_is($toks, $i, [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR])) {
                if (in_array($t[1], ['require_permission', 'requireAdmin'], true)) {
                    $stack[count($stack) - 1] = true;
                    $gates[] = rg_call_text($toks, $i);
                } elseif ($t[1] === 'authenticateAgentRequest') {
                    // An agent endpoint: agents have no role. Often called in a
                    // try block whose catch exits, so it covers the whole file.
                    $stack[0] = true;
                    $gates[] = 'agent-auth';
                } elseif ($t[1] === 'opnmgr_block_direct_web_access' && count($stack) === 1) {
                    $stack[0] = true;
                    $gates[] = 'cli-only';
                }
            }
            if (is_array($t) && $t[0] === T_IF) {
                [$cond, $body, $flat] = rg_if_parts($toks, $i);
                if ($flat && preg_match('/\b(exit|die)\b/i', $body)) {
                    if (preg_match('/!\s*(can|isAdmin)\s*\(/', $cond)) {
                        $stack[count($stack) - 1] = true;
                        $gates[] = 'if (' . trim(preg_replace('/\s+/', ' ', $cond)) . ') exit';
                    } elseif (rg_is_not_cli($cond)) {
                        $stack[count($stack) - 1] = true;
                        $gates[] = 'cli-only';
                    }
                } elseif (rg_is_not_cli($cond)
                          && preg_match('/^\s*(require_permission|requireAdmin)\s*\(/', $body, $m)) {
                    // `if (web) { require_permission(...); ... }`: web requests
                    // are gated, and the CLI path is not reachable over HTTP.
                    $stack[count($stack) - 1] = true;
                    $gates[] = 'web: ' . rg_call_text(token_get_all('<?php ' . ltrim($body)), 1);
                }
            }
            if (rg_is_write($toks, $i, $writers)) {
                $line = rg_line($toks, $i);
                $writes[] = $line;
                if (!in_array(true, $stack, true)) {
                    $ungated[] = $line;
                }
            }
        }
        return ['writes' => $writes, 'ungated' => array_values(array_unique($ungated)), 'gates' => $gates];
    }

    function rg_is_not_cli(string $cond): bool {
        return (bool)preg_match('/^\s*(PHP_SAPI|php_sapi_name\s*\(\s*\))\s*!==?\s*[\'"]cli[\'"]\s*$/', $cond);
    }

    function rg_opens($t): bool {
        return $t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true));
    }

    function rg_line(array $toks, int $i): int {
        for (; $i >= 0; $i--) {
            if (is_array($toks[$i])) { return $toks[$i][2]; }
        }
        return 0;
    }

    function rg_skip(array $toks, int $i, int $dir): int {
        for ($i += $dir; $i >= 0 && $i < count($toks); $i += $dir) {
            if (!(is_array($toks[$i]) && in_array($toks[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))) {
                return $i;
            }
        }
        return -1;
    }

    function rg_next(array $toks, int $i): ?string {
        $j = rg_skip($toks, $i, 1);
        return $j < 0 ? null : (is_array($toks[$j]) ? $toks[$j][1] : $toks[$j]);
    }

    function rg_prev_is(array $toks, int $i, array $types): bool {
        $j = rg_skip($toks, $i, -1);
        return $j >= 0 && is_array($toks[$j]) && in_array($toks[$j][0], $types, true);
    }

    /** @param array<string,bool> $writers same-file functions that write */
    function rg_is_write(array $toks, int $i, array $writers): bool {
        $t = $toks[$i];
        if (!is_array($t)) { return false; }
        if (in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            return (bool)preg_match('/\b(INSERT\s+(IGNORE\s+)?INTO|UPDATE\s+`?\w+`?\s+SET|DELETE\s+FROM|REPLACE\s+INTO)\b/i', $t[1]);
        }
        if ($t[0] !== T_STRING || rg_next($toks, $i) !== '('
            || rg_prev_is($toks, $i, [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR])) {
            return false;
        }
        $fn = strtolower($t[1]);
        return in_array($fn, ['shell_exec', 'exec', 'proc_open', 'passthru', 'system', 'popen',
                              'queue_firewall_command', 'queue_command'], true)
            || isset($writers[$fn]);
    }

    function rg_call_text(array $toks, int $i): string {
        $s = '';
        $d = 0;
        for (; $i < count($toks); $i++) {
            $x = is_array($toks[$i]) ? $toks[$i][1] : $toks[$i];
            $s .= $x;
            if ($x === '(') { $d++; }
            if ($x === ')' && --$d === 0) { break; }
        }
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** @return array{0:string,1:string,2:bool} condition, body, body has no nested block */
    function rg_if_parts(array $toks, int $i): array {
        $n = count($toks);
        $cond = '';
        $d = 0;
        for ($i++; $i < $n; $i++) {
            $x = is_array($toks[$i]) ? $toks[$i][1] : $toks[$i];
            if ($x === '(' && ++$d === 1) { continue; }
            if ($x === ')' && --$d === 0) { break; }
            if ($d >= 1) { $cond .= $x; }
        }
        $body = '';
        $flat = true;
        $d = 0;
        for ($i++; $i < $n; $i++) {
            $t = $toks[$i];
            $x = is_array($t) ? $t[1] : $t;
            if (rg_opens($t)) {
                if (++$d === 1) { continue; }
                $flat = false;
            }
            if ($x === '}' && --$d === 0) { break; }
            if ($d === 0 && $x === ';') { $body .= $x; break; }   // braceless if
            $body .= $x;
        }
        return [$cond, $body, $flat];
    }
}
