<?php
// Einfacher CLI-Selbsttest fuer die transport-unabhaengigen ServerQuery-
// Protokollfunktionen (kein PHPUnit noetig fuer diesen Projektumfang).
// Aufruf: php bin/selftest_parser.php
require __DIR__ . '/../html/lib/ts_protocol.php';

$failures = 0;

function check(string $label, $actual, $expected): void {
    global $failures;
    if ($actual !== $expected) {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
    } else {
        echo "ok - $label\n";
    }
}

// ts_escape()/ts_unescape() muessen sich fuer beliebige Kombinationen von
// Sonderzeichen exakt umkehren (z.B. ein ServerQuery-Login-Passwort, das
// Backslash, Slash, Leerzeichen, Pipe und Whitespace gleichzeitig enthaelt).
// Ein round-trip-Test deckt die kritische "Backslash zuerst"-Escape-
// Reihenfolge indirekt aber zuverlaessig ab: bei falscher Reihenfolge
// scheitert genau der "combo"-Fall unten.
$roundTripInputs = [
    'simple',
    'with space',
    'with\\backslash',
    'with/slash',
    'with|pipe',
    "with\ttab\nand\rnewlines",
    'combo: \\ / | ' . "\t\n\r" . ' together',
];
foreach ($roundTripInputs as $i => $input) {
    check("round-trip #$i", ts_unescape(ts_escape($input)), $input);
}

check('ts_parse_item simple pairs', ts_parse_item('cid=5 pid=0 channel_name=Test'), [
    'cid' => '5', 'pid' => '0', 'channel_name' => 'Test',
]);

check('ts_parse_item escaped value', ts_parse_item('channel_name=Foo\\sBar'), [
    'channel_name' => 'Foo Bar',
]);

check('ts_parse_list splits on pipe', ts_parse_list('cid=1 pid=0|cid=2 pid=0'), [
    ['cid' => '1', 'pid' => '0'],
    ['cid' => '2', 'pid' => '0'],
]);

check('ts_parse_single skips error line', ts_parse_single("error id=0 msg=ok\nvirtualserver_name=Test"), [
    'virtualserver_name' => 'Test',
]);

check('ts_uptime days', ts_uptime(90000), '1d 1h');
check('ts_uptime hours', ts_uptime(3700), '1h 1m');
check('ts_uptime minutes', ts_uptime(120), '2m');

if ($failures > 0) {
    fwrite(STDERR, "\n$failures Test(s) fehlgeschlagen.\n");
    exit(1);
}
echo "\nAlle Tests erfolgreich.\n";
