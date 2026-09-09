<?php
// ─── ServerQuery protocol syntax ───────────────────────────────────────────────
// Plain text syntax of the ServerQuery protocol (key=value, separated by
// spaces; lists separated by "|"). Transport- and TS-version-independent,
// applies identically to TS3/TS5/TS6 and to the SSH and raw-TCP transports.

// Single left-to-right scan instead of multiple str_replace() passes.
// A multi-pass approach (tried previously) is unsafe here: an escaped
// backslash is itself two characters ("\\"), and if the character right
// after that pair happens to be one of s/p/n/r/t or a literal "/" or space,
// an earlier str_replace() pass looking for e.g. "\s" can match across the
// boundary (the second "\" of the pair + the following real character) and
// corrupt the text - e.g. the wire form of "back\slash" (one real backslash)
// decoded to "back lash" instead. A single pass that consumes exactly one
// escape sequence at a time can't have this cross-boundary ambiguity.
function ts_unescape(string $s): string {
    $map = ['\\' => '\\', '/' => '/', 's' => ' ', ' ' => ' ', 'p' => '|', 'n' => "\n", 'r' => "\r", 't' => "\t"];
    $out = '';
    $len = strlen($s);
    for ($i = 0; $i < $len; $i++) {
        if ($s[$i] === '\\' && $i + 1 < $len && isset($map[$s[$i + 1]])) {
            $out .= $map[$s[$i + 1]];
            $i++;
        } else {
            $out .= $s[$i];
        }
    }
    return $out;
}

// Counterpart to ts_unescape(): escapes a value for an outgoing ServerQuery
// command (e.g. "login <user> <pass>" for the raw transport). No shell
// escaping - this is pure protocol syntax, independent of the transport.
// Order is critical: escape the backslash first, otherwise the backslashes
// newly introduced by the following replacements would get escaped again by mistake.
function ts_escape(string $s): string {
    $s = str_replace('\\', '\\\\', $s);
    $s = str_replace('/', '\\/', $s);
    $s = str_replace(' ', '\\s', $s);
    $s = str_replace('|', '\\p', $s);
    $s = str_replace("\n", '\\n', $s);
    $s = str_replace("\r", '\\r', $s);
    $s = str_replace("\t", '\\t', $s);
    return $s;
}

function ts_parse_item(string $item): array {
    $r = [];
    foreach (explode(' ', trim($item)) as $pair) {
        if ($pair === '') continue;
        if (strpos($pair, '=') !== false) { [$k,$v] = explode('=', $pair, 2); $r[$k] = ts_unescape($v); }
        else $r[$pair] = true;
    }
    return $r;
}

function ts_parse_list(string $raw): array {
    foreach (explode("\n", trim($raw)) as $line) {
        $line = trim($line);
        if ($line !== '' && strpos($line, 'error ') !== 0) {
            $r = [];
            foreach (explode('|', $line) as $item) { $p = ts_parse_item($item); if ($p) $r[] = $p; }
            return $r;
        }
    }
    return [];
}

function ts_parse_single(string $raw): array {
    foreach (explode("\n", trim($raw)) as $line) {
        $line = trim($line);
        if ($line !== '' && strpos($line, 'error ') !== 0) return ts_parse_item($line);
    }
    return [];
}

function ts_uptime(int $s): string {
    $d = floor($s/86400); $h = floor(($s%86400)/3600); $m = floor(($s%3600)/60);
    if ($d > 0) return "{$d}d {$h}h";
    if ($h > 0) return "{$h}h {$m}m";
    return "{$m}m";
}
