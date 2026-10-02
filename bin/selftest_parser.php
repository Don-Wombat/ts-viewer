<?php
// Simple CLI self-test for the transport-independent ServerQuery protocol
// functions (no PHPUnit needed for a project this size).
// Usage: php bin/selftest_parser.php
require __DIR__ . '/../html/lib/ts_protocol.php';
require __DIR__ . '/../html/lib/ts_online_time.php';

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

// --- Online-time leaderboard: pure merge/sort logic (no network needed) ---

$data = ts_online_time_apply([], [
    ['client_database_id' => '5', 'client_nickname' => 'Alice'],
    ['client_database_id' => '7', 'client_nickname' => 'Bob'],
], 60);
check('online-time: first poll credits both clients 60s', $data, [
    '5' => ['nickname' => 'Alice', 'seconds' => 60],
    '7' => ['nickname' => 'Bob', 'seconds' => 60],
]);

$data = ts_online_time_apply($data, [
    ['client_database_id' => '5', 'client_nickname' => 'Alice'],
], 60);
check('online-time: second poll only credits the still-online client', $data, [
    '5' => ['nickname' => 'Alice', 'seconds' => 120],
    '7' => ['nickname' => 'Bob', 'seconds' => 60],
]);

$data = ts_online_time_apply($data, [
    ['client_database_id' => '5', 'client_nickname' => 'AliceRenamed'],
], 60);
check('online-time: a rename overwrites the stored nickname', $data['5']['nickname'], 'AliceRenamed');
check('online-time: seconds keep accumulating across renames', $data['5']['seconds'], 180);

check('online-time: a client with no database id is skipped', ts_online_time_apply([], [
    ['client_nickname' => 'NoId'],
], 60), []);

// Alias merge (e.g. the same person's mobile + desktop client, two
// different TS identities): both the write path (apply, on the next poll)
// and the read path (top, for display) must fold the configured id into its
// target. A synthetic $aliases table is passed explicitly here instead of
// relying on TS_ONLINE_TIME_ALIASES (empty by default - see its comment in
// ts_online_time.php) so this is testable without a real seeded duplicate.
$testAliases = ['8' => '9'];
check('online-time apply: aliased id is merged into its target on write', ts_online_time_apply([
    '8' => ['nickname' => 'Bob', 'seconds' => 60],
    '9' => ['nickname' => 'Bob', 'seconds' => 1680],
], [
    ['client_database_id' => '9', 'client_nickname' => 'Bob'],
], 60, $testAliases), [
    '9' => ['nickname' => 'Bob', 'seconds' => 1800],
]);

check('online-time apply: a poll crediting the aliased id itself still lands on the target', ts_online_time_apply([
    '8' => ['nickname' => 'Bob', 'seconds' => 60],
    '9' => ['nickname' => 'Bob', 'seconds' => 1680],
], [
    ['client_database_id' => '8', 'client_nickname' => 'Bob'],
], 60, $testAliases), [
    // 60+1680 merged first, then +60 for this poll's interval.
    '9' => ['nickname' => 'Bob', 'seconds' => 1800],
]);

check('online-time top: aliased id is merged into its target for display', ts_online_time_top([
    '8' => ['nickname' => 'Bob', 'seconds' => 60],
    '9' => ['nickname' => 'Bob', 'seconds' => 1680],
    '3' => ['nickname' => 'Alice', 'seconds' => 51720],
], 5, $testAliases), [
    ['nickname' => 'Alice', 'seconds' => 51720],
    ['nickname' => 'Bob', 'seconds' => 1740],
]);

check('online-time top: sorted descending and limited', ts_online_time_top([
    '1' => ['nickname' => 'Low', 'seconds' => 10],
    '2' => ['nickname' => 'High', 'seconds' => 300],
    '3' => ['nickname' => 'Mid', 'seconds' => 100],
], 2), [
    ['nickname' => 'High', 'seconds' => 300],
    ['nickname' => 'Mid', 'seconds' => 100],
]);

if ($failures > 0) {
    fwrite(STDERR, "\n$failures test(s) failed.\n");
    exit(1);
}
echo "\nAll tests passed.\n";
