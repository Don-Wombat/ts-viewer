<?php
// Integration test for TsRawTransport against bin/mock_serverquery.php - covers
// the actual connect/login/parsing path, not just the plain protocol syntax
// like bin/selftest_parser.php.
// Usage: php bin/test_raw_transport.php
require __DIR__ . '/../html/lib/ts_protocol.php';
require __DIR__ . '/../html/lib/ts_transport.php';
require __DIR__ . '/../html/lib/ts_transport_raw.php';
require __DIR__ . '/../html/lib/ts_transport_ssh.php';
require __DIR__ . '/../html/lib/ts_client.php';
require __DIR__ . '/../html/lib/sounds.php';
require __DIR__ . '/../html/lib/auth.php';

$failures = 0;

function check(string $label, bool $ok): void {
    global $failures;
    if ($ok) {
        echo "ok - $label\n";
    } else {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n");
    }
}

function start_mock(int $port, string $mode): array {
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = sprintf('php %s %d %s', escapeshellarg(__DIR__ . '/mock_serverquery.php'), $port, escapeshellarg($mode));
    $proc = proc_open($cmd, $desc, $pipes);
    if (!is_resource($proc)) {
        fwrite(STDERR, "Could not start the mock\n");
        exit(1);
    }
    // Wait for the "listening" line instead of sleeping for a fixed time (avoids flakiness in CI).
    stream_set_timeout($pipes[1], 5);
    $line = fgets($pipes[1]);
    if (trim((string)$line) !== 'listening') {
        fwrite(STDERR, "Mock did not come up in time (output: " . var_export($line, true) . ")\n");
        exit(1);
    }
    return [$proc, $pipes];
}

function stop_mock(array $procAndPipes): void {
    [$proc, $pipes] = $procAndPipes;
    foreach ($pipes as $p) { if (is_resource($p)) fclose($p); }
    proc_terminate($proc);
    proc_close($proc);
}

$basePort = 20011 + random_int(0, 5000);

// --- Happy path: login with a password containing special characters + full data receipt ---
$mock = start_mock($basePort, 'ok');
try {
    $config = [
        'host' => '127.0.0.1',
        'port' => $basePort,
        'user' => 'testuser',
        'pass' => 'te\\st pass|word', // backslash, space, pipe all at once
        'connect_timeout' => 5,
    ];
    $transport = new TsRawTransport($config);
    $out = $transport->query("use port=9987\nserverinfo\nchannellist\nclientlist\nquit\n");

    // Uses the actual shared classifier from html/lib/ts_client.php now
    // (used to be a separate copy here, which once drifted out of sync with
    // a security fix made only to the original) - this also tests that the
    // transport and the existing parsers actually fit together.
    $lines = ts_classify_bundle_lines($out);
    $info = ts_parse_single($lines['serverinfo']);
    $channels = ts_parse_list($lines['channellist']);
    $clients = ts_parse_list($lines['clientlist']);
    $servergroups = ts_parse_list($lines['servergrouplist']);

    check('serverinfo contains the expected server name', ($info['virtualserver_name'] ?? null) === 'MockServer');
    check('channellist contains Lobby', ($channels[0]['channel_name'] ?? null) === 'Lobby');
    check('channellist contains the topic', ($channels[0]['channel_topic'] ?? null) === 'Welcome');
    check('clientlist contains Alice', ($clients[0]['client_nickname'] ?? null) === 'Alice');
    check('clientlist contains mute status', ($clients[0]['client_input_muted'] ?? null) === '1');
    check('clientlist contains client_database_id (used by the online-time tracker)', ($clients[0]['client_database_id'] ?? null) === '1');
    check('servergrouplist contains Server Admin (escaped)', ($servergroups[0]['name'] ?? null) === 'Server Admin');
} finally {
    stop_mock($mock);
}

// --- Quote box: channelinfo folded into the same bundle must not corrupt
// channellist (its response line also contains "channel_name=") ---
$mock3 = start_mock($basePort + 2, 'ok');
try {
    $config3 = [
        'host' => '127.0.0.1',
        'port' => $basePort + 2,
        'user' => 'testuser',
        'pass' => 'testpass',
        'connect_timeout' => 5,
    ];
    $transport3 = new TsRawTransport($config3);
    $out3 = $transport3->query("use port=9987\nserverinfo\nchannellist\nclientlist\nservergrouplist\nchannelinfo cid=33\nquit\n");
    $lines3 = ts_classify_bundle_lines($out3);

    check('channellist still contains Lobby (not overwritten by channelinfo)',
        (ts_parse_list($lines3['channellist'])[0]['channel_name'] ?? null) === 'Lobby');
    $quotes = ts_parse_quotes($lines3['channeldescription']);
    check('quote box: two quotes parsed from the channel description', count($quotes) === 2);
    check('quote box: first quote text', ($quotes[0] ?? null) === '"Test quote" - Mock 2020');
    check('quote box: second quote (year-only attribution) text', ($quotes[1] ?? null) === '"Second quote" - 2021');
} finally {
    stop_mock($mock3);
}

// --- Failure case: server rejects the login -> TsAuthException expected ---
$mock2 = start_mock($basePort + 1, 'fail');
try {
    $config2 = [
        'host' => '127.0.0.1',
        'port' => $basePort + 1,
        'user' => 'testuser',
        'pass' => 'wrong',
        'connect_timeout' => 5,
    ];
    $transport2 = new TsRawTransport($config2);
    $threw = false;
    try {
        $transport2->query("use port=9987\nquit\n");
    } catch (TsAuthException $e) {
        $threw = true;
    }
    check('TsAuthException on failed login', $threw);
} finally {
    stop_mock($mock2);
}

// --- Quote box: text/attribution splitting, including formats found on the
// real server (dash directly against the name, year-only attribution, an
// embedded newline inside one quote) ---
require __DIR__ . '/../html/lib/i18n.php';
require __DIR__ . '/../html/lib/cache.php';
require __DIR__ . '/../html/lib/render.php';

$attributionCases = [
    ['"Quote" - Name 2020', '"Quote"', '— Name 2020'],
    ['"Quote" -Name 2022', '"Quote"', '— Name 2022'],      // no space before the name
    ['Dialogue with no named author. -2022', 'Dialogue with no named author.', '— 2022'],
    ["Multi\nline quote text. - Name 2021", "Multi\nline quote text.", '— Name 2021'],
    ['No attribution at all here', 'No attribution at all here', ''],
];
foreach ($attributionCases as $i => [$input, $expectedText, $expectedAttr]) {
    [$text, $attr] = ts_split_quote_attribution($input);
    check("quote attribution split #$i: text", $text === $expectedText);
    check("quote attribution split #$i: attribution", $attr === $expectedAttr);
}

// --- Spacer-channel rendering: matches the TeamSpeak client's own
// "[<align>spacerN]label" convention (l/c/r = aligned heading, */none =
// plain divider line) instead of the app's earlier approximation, which
// hid bare spacers entirely and rendered labeled ones ("[cspacer] Talk
// Channels") as completely normal, icon-bearing channels. Cases below are
// all names actually seen on the live server, plus the removed hardcoded
// "cid=2" exception (present, unexplained, since the project's very first
// commit - excluded cid=2 from spacer handling, so "[spacer5]___" at
// cid=2 rendered as a normal channel literally named "___"). ---
$spacerCmap = [
    '3' => ['cid' => '3', 'pid' => '0', 'channel_name' => '[cspacer]Section Title'],
    '2' => ['cid' => '2', 'pid' => '0', 'channel_name' => '[spacer5]___'],
    '5' => ['cid' => '5', 'pid' => '0', 'channel_name' => '[cspacer0]'],
    '9' => ['cid' => '9', 'pid' => '0', 'channel_name' => '[lspacer3]Left Label'],
    '11' => ['cid' => '11', 'pid' => '0', 'channel_name' => '[rspacer2]Right Label'],
    '13' => ['cid' => '13', 'pid' => '0', 'channel_name' => 'Not A Spacer'],
];
$spacerChildren = ['0' => ['3', '2', '5', '9', '11', '13']];
$spacerHtml = ts_render_channels($spacerChildren, $spacerCmap, [], '0', 0, 32, [], null);
// (checks the heading is a distinct ch-spacer-label element, not that
// "ch-icon" is absent anywhere in the output - the batch below also
// includes an ordinary channel, which legitimately has one)
check('"[cspacer]Section Title" renders as a centered heading, not a normal channel',
    str_contains($spacerHtml, 'ch-spacer-label ch-spacer-center"') && str_contains($spacerHtml, '>Section Title</div>'));
check('"[spacer5]___" (cid=2, the old hardcoded exception) renders as a plain divider',
    !str_contains($spacerHtml, '___'));
check('bare "[cspacer0]" (no label) renders as a plain divider, not a blank channel row',
    !str_contains($spacerHtml, 'ch-name"></span>'));
check('"[lspacer3]Left Label" is left-aligned',
    str_contains($spacerHtml, 'ch-spacer-label ch-spacer-left"') && str_contains($spacerHtml, '>Left Label</div>'));
check('"[rspacer2]Right Label" is right-aligned',
    str_contains($spacerHtml, 'ch-spacer-label ch-spacer-right"') && str_contains($spacerHtml, '>Right Label</div>'));
check('a real channel that just happens to not match the spacer tag still renders normally',
    str_contains($spacerHtml, 'ch-name">Not A Spacer</span>'));

$onlySpacersHtml = ts_render_channels(['0' => ['3', '2', '5', '9', '11']], $spacerCmap, [], '0', 0, 32, [], null);
check('spacers never get a folder icon or click-styled channel row',
    !str_contains($onlySpacersHtml, 'ch-icon') && !str_contains($onlySpacersHtml, 'class="channel'));
check('exactly 2 plain divider lines rendered (spacer5 and cspacer0)',
    substr_count($spacerHtml, 'ch-spacer-line') === 2);

// --- Hidden channels (TS_HIDDEN_CHANNELS): matches names actually seen on
// the live server - "[cspacer]Special Channels" is itself a section-heading
// spacer, so hiding "Special Channels" must match on its label, not the raw
// "[cspacer]..." tag. Hiding "Quote Box" must also swallow the plain
// "[spacer7]___" divider directly after it (dangling otherwise), but must
// NOT touch the unrelated "[spacer6]___" above the section or the
// "[cspacer]Temporary Channels" heading that follows. ---
$hiddenCmap = [
    '31' => ['cid' => '31', 'pid' => '0', 'channel_name' => '[spacer6]___'],
    '32' => ['cid' => '32', 'pid' => '0', 'channel_name' => '[cspacer]Special Channels'],
    '5'  => ['cid' => '5',  'pid' => '0', 'channel_name' => "\u{2191} Data Share \u{2193}"],
    '33' => ['cid' => '33', 'pid' => '0', 'channel_name' => 'Quote Box'],
    '34' => ['cid' => '34', 'pid' => '0', 'channel_name' => '[spacer7]___'],
    '35' => ['cid' => '35', 'pid' => '0', 'channel_name' => '[cspacer]Temporary Channels'],
];
$hiddenChildren = ['0' => ['31', '32', '5', '33', '34', '35']];
$hiddenNames = ['Special Channels', "\u{2191} Data Share \u{2193}", 'Quote Box'];
$hiddenHtml = ts_render_channels($hiddenChildren, $hiddenCmap, [], '0', 0, 32, [], null, $hiddenNames);
check('hidden spacer heading "Special Channels" does not appear',
    !str_contains($hiddenHtml, 'Special Channels'));
check('hidden channel "Data Share" does not appear',
    !str_contains($hiddenHtml, 'Data Share'));
check('hidden channel "Quote Box" does not appear',
    !str_contains($hiddenHtml, 'Quote Box'));
check('exactly 1 divider line left (the unrelated one above the section, cid=31 kept; cid=34 below the hidden "Quote Box" is swallowed)',
    substr_count($hiddenHtml, 'ch-spacer-line') === 1);
check('the following section heading "Temporary Channels" is kept',
    str_contains($hiddenHtml, '>Temporary Channels</div>'));

// Without any hidden names configured, nothing changes (default/off behavior).
$unfilteredHtml = ts_render_channels($hiddenChildren, $hiddenCmap, [], '0', 0, 32, [], null, []);
check('TS_HIDDEN_CHANNELS empty -> nothing is filtered',
    str_contains($unfilteredHtml, 'Special Channels') && str_contains($unfilteredHtml, 'Quote Box'));

// --- Soundboard: file listing/labeling (html/lib/sounds.php), against a
// throwaway directory tree mirroring the real archive's shape - a mix of
// root-level files and one subfolder per person, plus a non-audio file that
// must be skipped. ---
$soundsDir = sys_get_temp_dir() . '/ts_viewer_test_sounds_' . bin2hex(random_bytes(4));
mkdir($soundsDir);
mkdir($soundsDir . '/Benny');
file_put_contents($soundsDir . '/Artikel 13 Song.mp3', 'x');
file_put_contents($soundsDir . '/klopf klopf.mp3', 'x');
file_put_contents($soundsDir . '/1637489454222.jpg', 'x'); // not audio - must be skipped
file_put_contents($soundsDir . '/Benny/Benny fettsau.mp3', 'x');
file_put_contents($soundsDir . '/Benny/Nieser.wav', 'x');

$soundGroups = ts_sounds_list($soundsDir);
check('root-level files are grouped under "" (shown as "General" on the page)',
    isset($soundGroups['']) && in_array('Artikel 13 Song.mp3', $soundGroups[''], true) && in_array('klopf klopf.mp3', $soundGroups[''], true));
check('the non-audio .jpg file is skipped entirely',
    !in_array('1637489454222.jpg', $soundGroups[''] ?? [], true));
check('a subfolder becomes its own group, keyed by folder name',
    isset($soundGroups['Benny']) && in_array('Benny/Benny fettsau.mp3', $soundGroups['Benny'], true) && in_array('Benny/Nieser.wav', $soundGroups['Benny'], true));
check('the root group ("") sorts first',
    array_key_first($soundGroups) === '');

check('label: filename uppercased, extension stripped, folder prefix dropped',
    ts_sound_label('Benny/Benny fettsau.mp3') === 'BENNY FETTSAU');

check('resolve: an existing file inside a subfolder resolves to a real, in-bounds path',
    ts_sound_resolve($soundsDir, 'Benny/Benny fettsau.mp3') === realpath($soundsDir . '/Benny/Benny fettsau.mp3'));
check('resolve: a ".." path-traversal attempt is rejected',
    ts_sound_resolve($soundsDir, '../etc/passwd') === null);
check('resolve: a disallowed extension (the .jpg) is rejected even though the file exists',
    ts_sound_resolve($soundsDir, '1637489454222.jpg') === null);
check('resolve: a non-existent file is rejected',
    ts_sound_resolve($soundsDir, 'Benny/does not exist.mp3') === null);
check('resolve: a NUL byte in the request is rejected',
    ts_sound_resolve($soundsDir, "Benny/Benny fettsau.mp3\0.jpg") === null);

unlink($soundsDir . '/Artikel 13 Song.mp3');
unlink($soundsDir . '/klopf klopf.mp3');
unlink($soundsDir . '/1637489454222.jpg');
unlink($soundsDir . '/Benny/Benny fettsau.mp3');
unlink($soundsDir . '/Benny/Nieser.wav');
rmdir($soundsDir . '/Benny');
rmdir($soundsDir);

// --- Soundboard: the signed auth cookie (html/lib/auth.php) ---
$cookie = ts_soundboard_make_cookie_value('correct-password');
check('a freshly made cookie verifies against the same password',
    ts_soundboard_verify_cookie_value($cookie, 'correct-password'));
check('the same cookie does NOT verify against a different password (also covers a changed TS_SOUNDBOARD_PASSWORD invalidating old cookies)',
    !ts_soundboard_verify_cookie_value($cookie, 'a-different-password'));
check('a tampered signature is rejected',
    !ts_soundboard_verify_cookie_value(substr($cookie, 0, -1) . (substr($cookie, -1) === 'A' ? 'B' : 'A'), 'correct-password'));
check('garbage input is rejected, not a fatal error',
    !ts_soundboard_verify_cookie_value('not-a-real-cookie-value', 'correct-password'));
check('an empty/missing cookie is rejected',
    !ts_soundboard_verify_cookie_value(null, 'correct-password') && !ts_soundboard_verify_cookie_value('', 'correct-password'));

$expiredPayload = base64_encode((string)(time() - TS_SOUNDBOARD_MAX_AGE - 60));
$expiredSig = base64_encode(hash_hmac('sha256', $expiredPayload, ts_soundboard_key('correct-password'), true));
check('a cookie older than TS_SOUNDBOARD_MAX_AGE is rejected',
    !ts_soundboard_verify_cookie_value($expiredPayload . '.' . $expiredSig, 'correct-password'));

// --- Soundboard: login throttle (html/lib/auth.php) - a security-review
// finding: without this, a script (curl never sends Sec-Fetch-Site, so it
// skips that CSRF-ish check entirely) could try passwords against this
// public endpoint with no delay at all. ---
$now = 1_700_000_000; // fixed reference instant, independent of wall-clock time
check('no prior record -> allowed',
    ts_login_throttle_is_allowed([], '1.2.3.4', $now));

$data = [];
for ($i = 0; $i < TS_LOGIN_THROTTLE_MAX_ATTEMPTS; $i++) {
    check("attempt " . ($i + 1) . " of the limit is still allowed",
        ts_login_throttle_is_allowed($data, '1.2.3.4', $now));
    $data = ts_login_throttle_apply_failure($data, '1.2.3.4', $now);
}
check('one more attempt beyond the limit (within the same window) is blocked',
    !ts_login_throttle_is_allowed($data, '1.2.3.4', $now));
check('a different IP is entirely unaffected by another IP\'s failures',
    ts_login_throttle_is_allowed($data, '5.6.7.8', $now));
check('after the window has elapsed, the same IP is allowed again',
    ts_login_throttle_is_allowed($data, '1.2.3.4', $now + TS_LOGIN_THROTTLE_WINDOW + 1));

$dataAfterClear = ts_login_throttle_clear($data, '1.2.3.4');
check('a successful login clears the record, allowing immediately again',
    ts_login_throttle_is_allowed($dataAfterClear, '1.2.3.4', $now));

// ts_client_ip(): reads X-Forwarded-For (first/leftmost = the real client,
// see the comment on the function), falls back to REMOTE_ADDR.
$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9, 10.0.0.1';
check('ts_client_ip() takes the first (leftmost) entry of X-Forwarded-For',
    ts_client_ip() === '9.9.9.9');
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
check('ts_client_ip() falls back to REMOTE_ADDR without X-Forwarded-For',
    ts_client_ip() === '127.0.0.1');

if ($failures > 0) {
    fwrite(STDERR, "\n$failures test(s) failed.\n");
    exit(1);
}
echo "\nAll integration tests passed.\n";
