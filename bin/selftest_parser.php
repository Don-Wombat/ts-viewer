<?php
// Simple CLI self-test for the transport-independent ServerQuery protocol
// functions (no PHPUnit needed for a project this size).
// Usage: php bin/selftest_parser.php
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

// ts_escape()/ts_unescape() must invert each other exactly for any
// combination of special characters (e.g. a ServerQuery login password that
// contains backslash, slash, space, pipe and whitespace at the same time).
// A round-trip test covers the critical "backslash first" escape order
// indirectly but reliably: with the wrong order, exactly the "combo" case
// below fails.
$roundTripInputs = [
    'simple',
    'with space',
    'with\\backslash',
    'with/slash',
    'with|pipe',
    "with\ttab\nand\rnewlines",
    'combo: \\ / | ' . "\t\n\r" . ' together',
    // Regression test: a literal backslash immediately followed by a
    // character that is itself an escape-sequence letter (s/p/n/r/t). A
    // previous ts_unescape() implementation ran a separate str_replace()
    // pass per escape sequence, so the second backslash of the doubled pair
    // produced by ts_escape() plus the following real letter (e.g. "\\" + "s")
    // could be misread as the unrelated "\s" (space) escape - e.g.
    // "back\slash" round-tripped to "back lash" instead of "back\slash".
    'back\\slash',
    'end\\pipe',
    'esc\\return',
];
foreach ($roundTripInputs as $i => $input) {
    check("round-trip #$i", ts_unescape(ts_escape($input)), $input);
}

// Direct regression check (bypassing ts_escape()) for the exact failure
// mode found in production: the raw wire form of "back\slash" (one literal
// backslash) is two literal backslash characters on the wire.
check('ts_unescape backslash-then-s does not become a space', ts_unescape('back' . '\\' . '\\' . 'slash'), 'back\\slash');

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
    fwrite(STDERR, "\n$failures test(s) failed.\n");
    exit(1);
}
echo "\nAll tests passed.\n";
