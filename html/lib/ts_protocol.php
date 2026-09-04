<?php
// ─── ServerQuery-Protokollsyntax ───────────────────────────────────────────────
// Reine Text-Syntax des ServerQuery-Protokolls (key=value, durch Leerzeichen
// getrennt; Listen durch "|" getrennt). Transport- und TS-Versions-unabhängig,
// gilt identisch fuer TS3/TS5/TS6 und fuer SSH- wie Raw-TCP-Transport.

function ts_unescape(string $s): string {
    $s = str_replace(['\\/', '\\s', '\\ ', '\\p', '\\n', '\\r', '\\t'], ['/', ' ', ' ', '|', "\n", "\r", "\t"], $s);
    // Escapten Backslash zuletzt aufloesen (Gegenstueck zu ts_escape(), das ihn
    // zuerst escaped) - fehlte im Original, faellt bei Namen mit "\" auf.
    return str_replace('\\\\', '\\', $s);
}

// Gegenstück zu ts_unescape(): escaped einen Wert fuer ein ausgehendes
// ServerQuery-Kommando (z.B. "login <user> <pass>" beim Raw-Transport). Kein
// Shell-Escaping - das ist reine Protokollsyntax, unabhängig vom Transport.
// Reihenfolge kritisch: Backslash zuerst ersetzen, sonst werden die durch die
// folgenden Ersetzungen neu eingefügten Backslashes faelschlich erneut escaped.
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
